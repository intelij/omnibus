<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\SeatInventoryService;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Production-hardening behaviours: security headers, rate limits, request
 * IDs, payment amount verification, late-payment reconciliation, promo
 * inventory races, payment-method switching, and PII rights.
 */
class HardeningTest extends TestCase
{
    // ─── Security headers + request IDs ──────────────────────────────

    public function test_api_responses_carry_security_headers_and_request_id(): void
    {
        $tenant = $this->makeTenant();
        $res = $this->publicGet($tenant, '/api/v1/public/tenant');

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($res->headers->get('X-Request-ID'));
    }

    public function test_inbound_request_id_is_echoed_back(): void
    {
        $tenant = $this->makeTenant();
        $res = $this->getJson('/api/v1/public/tenant?tenant='.$tenant->slug, ['X-Request-ID' => 'req_abc1234567890']);
        $res->assertHeader('X-Request-ID', 'req_abc1234567890');
    }

    public function test_login_rate_limit_returns_429(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'tenant_admin');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    // ─── Payment integrity ───────────────────────────────────────────

    public function test_paid_webhook_with_wrong_amount_flags_discrepancy_not_confirms(): void
    {
        [$booking, $payment] = $this->bookingWithEcocashPayment();

        $payload = json_encode([
            'transactionReference' => $payment->provider_ref,
            'reference' => $booking->booking_reference,
            'status' => 'success',
            'amount' => (float) $payment->amount - 10, // underpaid
        ]);
        $sig = hash_hmac('sha256', $payload, 'test-secret');

        $this->call('POST', '/api/v1/webhooks/payments/ecocash', [], [], [],
            ['HTTP_X_ECOCASH_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();

        $this->assertNotSame('paid', $payment->fresh()->status);
        $this->assertSame('pending_payment', $booking->fresh()->status);
        $d = PaymentDiscrepancy::withoutTenantScope()->first();
        $this->assertNotNull($d);
        $this->assertSame(PaymentDiscrepancy::TYPE_AMOUNT_MISMATCH, $d->type);
        $this->assertSame('open', $d->status);
    }

    public function test_payment_arriving_after_booking_expired_flags_late_payment(): void
    {
        [$booking, $payment] = $this->bookingWithEcocashPayment();

        // Expire the booking underneath the still-open payment.
        $this->travel(31)->minutes();
        app(SeatInventoryService::class)->releaseExpiredHolds();
        app(BookingService::class)->expireStaleBookings();
        $booking->refresh();
        $this->assertSame('expired', $booking->status);

        // Force payment back to an open state — expiry cascades may have
        // already closed it; the scenario is "provider says paid late".
        $payment->refresh();
        if ($payment->status !== 'processing') {
            $payment->status = 'processing';
            $payment->expires_at = null;
            $payment->save();
        }

        $payload = json_encode([
            'transactionReference' => $payment->provider_ref,
            'reference' => $booking->booking_reference,
            'status' => 'success',
            'amount' => (float) $payment->amount,
        ]);
        $sig = hash_hmac('sha256', $payload, 'test-secret');

        $this->call('POST', '/api/v1/webhooks/payments/ecocash', [], [], [],
            ['HTTP_X_ECOCASH_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();

        $this->assertSame('paid', $payment->fresh()->status); // money really arrived
        $this->assertSame('expired', $booking->fresh()->status); // booking not resurrected
        $d = PaymentDiscrepancy::withoutTenantScope()->latest()->first();
        $this->assertSame(PaymentDiscrepancy::TYPE_LATE_PAYMENT, $d->type);
    }

    public function test_switching_payment_method_cancels_previous_open_payment(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $booking = Booking::withoutTenantScope()->findOrFail(
            $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, ['payment_method' => null]))->json('data.id')
        );

        $p1 = $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/payments?email=pax@example.com", ['method' => 'cash']);
        $p2 = $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/payments?email=pax@example.com", ['method' => 'card']);
        $p2->assertCreated();

        $this->assertSame('cancelled', Payment::findOrFail($p1->json('payment.id'))->status);
        $this->assertNotSame($p1->json('payment.id'), $p2->json('payment.id'));
        // Exactly one payable payment remains.
        $this->assertEquals(1, $booking->fresh()->openPayment->id === $p2->json('payment.id'));
    }

    // ─── Promotion race ──────────────────────────────────────────────

    public function test_inventory_limited_promo_cannot_be_double_redeemed(): void
    {
        $tenant = $this->makeTenant();
        $tripA = $this->makeTrip($tenant);
        $tripB = $this->makeTrip($tenant); // different seat locks — real race surface

        Promotion::create([
            'tenant_id' => $tenant->id, 'code' => 'ONE-OFF', 'name' => 'One use',
            'type' => 'fixed', 'value' => 5, 'usage_limit' => 1, 'status' => 'active',
        ]);

        $payload = ['promotion_code' => 'ONE-OFF', 'payment_method' => 'cash'];

        $a = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($tripA, $payload));
        $b = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($tripB, $payload));

        $a->assertCreated();
        $b->assertStatus(422); // promo exhausted — booking rejected, not silently full-priced
        $this->assertEquals(1, PromotionRedemption::count());
    }

    // ─── PII rights ──────────────────────────────────────────────────

    public function test_guest_can_export_and_anonymize_their_pii(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $finance = $this->makeUser($tenant, 'finance_officer');

        $booking = Booking::withoutTenantScope()->findOrFail(
            $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip))->json('data.id')
        );

        // Export needs the contact key.
        $this->publicGet($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/privacy-export")
            ->assertStatus(403);
        $export = $this->getJson("/api/v1/public/bookings/{$booking->booking_reference}/privacy-export?email=pax@example.com&tenant={$tenant->slug}");
        $export->assertOk();
        $this->assertSame('pax@example.com', $export->json('data.booking.contact.email'));
        $this->assertSame('Test Pax', $export->json('data.passengers.0.name'));

        // Active bookings can't be anonymized — confirm then travel.
        $this->actingAs($finance, 'sanctum');
        $this->postJson("/api/v1/payments/{$booking->openPayment->id}/confirm")->assertOk();
        $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/anonymize?email=pax@example.com")
            ->assertStatus(422);

        $booking->forceFill(['status' => 'completed'])->save();
        $this->publicPost($tenant, "/api/v1/public/bookings/{$booking->booking_reference}/anonymize?email=pax@example.com")
            ->assertOk();

        $fresh = $booking->fresh();
        $this->assertNull($fresh->contact_email);
        $this->assertSame('REDACTED', $fresh->contact_name);
        $this->assertSame('REDACTED', $fresh->passengers->first()->first_name);
        // Financial record preserved.
        $this->assertGreaterThan(0, (float) $fresh->total);
    }

    public function test_audit_metadata_masks_pii(): void
    {
        $tenant = $this->makeTenant();
        app(TenantContext::class)->set($tenant);

        Audit::record('test.event', null, [
            'email' => 'customer@example.com',
            'phone' => '+263771234567',
            'api_key' => 'sekret',
        ]);

        $meta = AuditLog::latest()->first()->metadata;
        $this->assertSame('[redacted]', $meta['api_key']);
        $this->assertStringContainsString('*', $meta['email']);
        $this->assertStringNotContainsString('customer@example.com', json_encode($meta));
        $this->assertStringContainsString('4567', $meta['phone']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function bookingWithEcocashPayment(): array
    {
        $tenant = $this->makeTenant();
        config()->set('transithub.payment_gateways.ecocash.webhook_secret', 'test-secret');
        config()->set('transithub.payment_gateways.ecocash.merchant_code', 'M');
        config()->set('transithub.payment_gateways.ecocash.merchant_pin', 'P');
        config()->set('transithub.payment_gateways.ecocash.endpoint', 'http://localhost');

        Http::fake(['*' => Http::response(['transactionReference' => 'ECX-'.uniqid()], 200)]);

        $trip = $this->makeTrip($tenant);
        $booking = Booking::withoutTenantScope()->findOrFail(
            $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, ['payment_method' => null]))->json('data.id')
        );
        $payment = app(PaymentService::class)->initiate($booking, 'ecocash');
        $payment->update(['provider_ref' => 'ECX-TEST-1']);

        return [$booking->fresh(), $payment->fresh()];
    }
}
