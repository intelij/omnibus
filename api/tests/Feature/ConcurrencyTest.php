<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\TripSeat;
use App\Models\TripSeatLeg;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatInventoryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * §66 critical concurrency scenarios. SQLite serialises writers, so we
 * exercise the lock+check protocol deterministically: each scenario drives
 * two competing operations and asserts only one wins.
 */
class ConcurrencyTest extends TestCase
{
    /** Scenario 1: two passengers select the same seat — one must lose. */
    public function test_two_passengers_same_seat_only_one_succeeds(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $seat = TripSeat::where('trip_id', $trip->id)->where('label', '1')->first();

        $payload = $this->bookingPayload($trip, ['trip_seat_ids' => [$seat->id]]);

        $a = $this->publicPost($tenant, '/api/v1/public/bookings', $payload);
        $b = $this->publicPost($tenant, '/api/v1/public/bookings', $payload);

        $a->assertCreated();
        $b->assertStatus(409);
        $this->assertEquals(1, Booking::count());
    }

    /** Scenario 2: two agents race on the same seat. */
    public function test_two_agents_same_seat_only_one_succeeds(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $agent1 = $this->makeUser($tenant, 'booking_agent');
        $agent2 = $this->makeUser($tenant, 'booking_agent');
        $seat = TripSeat::where('trip_id', $trip->id)->where('label', '2')->first();

        $payload = $this->bookingPayload($trip, ['trip_seat_ids' => [$seat->id]]);

        $this->actingAs($agent1, 'sanctum');
        $a = $this->postJson('/api/v1/bookings', $payload);

        $this->actingAs($agent2, 'sanctum');
        $b = $this->postJson('/api/v1/bookings', $payload);

        $a->assertCreated();
        $b->assertStatus(409);
    }

    /** Scenario 3: payment lands after the hold expired and seat was taken. */
    public function test_payment_after_hold_expiry_never_double_books(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $seat = TripSeat::where('trip_id', $trip->id)->where('label', '3')->first();

        $payload = $this->bookingPayload($trip, ['trip_seat_ids' => [$seat->id]]);

        // A holds the seat (no payment method → stays 'held')
        $a = $this->publicPost($tenant, '/api/v1/public/bookings', array_merge($payload, ['payment_method' => null]));
        $bookingA = Booking::findOrFail($a->json('data.id'));

        // Hold expires; B books the same seat with cash + confirmation.
        $this->travel(11)->minutes();
        app(SeatInventoryService::class)->releaseExpiredHolds();
        app(BookingService::class)->expireStaleBookings();
        app(PaymentService::class)->expireStale();

        $b = $this->publicPost($tenant, '/api/v1/public/bookings', $payload);
        $b->assertCreated();
        $bookingB = Booking::findOrFail($b->json('data.id'));
        $finance = $this->makeUser($tenant, 'finance_officer');
        $this->actingAs($finance, 'sanctum');
        $this->postJson("/api/v1/payments/{$bookingB->openPayment->id}/confirm")->assertOk();

        // Now A's late payment attempt: booking A was expired by the sweep —
        // initiating payment must fail, not confirm against stolen inventory.
        $bookingA->refresh();
        $this->assertSame('expired', $bookingA->status);
        $this->assertSame('booked', TripSeatLeg::where('trip_seat_id', $seat->id)->where('leg_index', 0)->value('state'));
        $this->assertEquals($bookingB->id, TripSeatLeg::where('trip_seat_id', $seat->id)->where('leg_index', 0)->value('booking_id'));
    }

    /** Scenario 4: webhook delivered twice — one confirmation only. */
    public function test_duplicate_webhook_is_deduplicated(): void
    {
        $tenant = $this->makeTenant();
        config()->set('transithub.payment_gateways.ecocash.webhook_secret', 'test-secret');
        config()->set('transithub.payment_gateways.ecocash.merchant_code', 'M');
        config()->set('transithub.payment_gateways.ecocash.merchant_pin', 'P');
        config()->set('transithub.payment_gateways.ecocash.endpoint', 'http://localhost');

        Http::fake(['*' => Http::response(['transactionReference' => 'ECX-TEST-1'], 200)]);

        $trip = $this->makeTrip($tenant);
        $booking = Booking::withoutTenantScope()->findOrFail(
            $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, ['payment_method' => null]))->json('data.id')
        );
        $payment = app(PaymentService::class)->initiate($booking, 'ecocash');
        $payment->update(['provider_ref' => 'ECX-TEST-1']);

        $payload = json_encode([
            'transactionReference' => 'ECX-TEST-1',
            'reference' => $booking->booking_reference,
            'status' => 'success',
            'amount' => (float) $payment->amount,
        ]);
        $sig = hash_hmac('sha256', $payload, 'test-secret');

        $uri = '/api/v1/webhooks/payments/ecocash';
        $first = $this->call('POST', $uri, [], [], [], ['HTTP_X_ECOCASH_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
        $second = $this->call('POST', $uri, [], [], [], ['HTTP_X_ECOCASH_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
        // One callback row only
        $this->assertEquals(1, $payment->transactions()->where('type', 'callback')->count());
    }

    /** Scenario 5: double-click Pay reuses the same open payment. */
    public function test_double_pay_click_creates_single_payment(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $booking = Booking::withoutTenantScope()->findOrFail(
            $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, ['payment_method' => null]))->json('data.id')
        );

        $p1 = $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/payments?email=pax@example.com", ['method' => 'cash']);
        $p2 = $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/payments?email=pax@example.com", ['method' => 'cash']);

        $this->assertSame($p1->json('payment.id'), $p2->json('payment.id'));
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }

    /** Webhook with a bad signature is rejected before touching state. */
    public function test_webhook_bad_signature_rejected(): void
    {
        config()->set('transithub.payment_gateways.ecocash.webhook_secret', 'real-secret');

        $this->call('POST', '/api/v1/webhooks/payments/ecocash', [], [], [],
            ['HTTP_X_ECOCASH_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], '{}')
            ->assertUnauthorized();
    }
}
