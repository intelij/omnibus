<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TripSeatLeg;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    public function test_full_cash_booking_flow(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $response = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, [
            'passengers' => [
                ['first_name' => 'A', 'last_name' => 'One'],
                ['first_name' => 'B', 'last_name' => 'Two'],
            ],
        ]));

        $response->assertCreated();
        $booking = Booking::findOrFail($response->json('data.id'));

        $this->assertSame('pending_payment', $booking->status);
        $this->assertEquals(50, (float) $booking->subtotal);
        $this->assertEquals(50, (float) $booking->total);
        $this->assertCount(2, $booking->passengers);
        $this->assertCount(2, $booking->seats);

        // Seats held on all legs of the segment
        $this->assertEquals(4, TripSeatLeg::where('booking_id', $booking->id)->where('state', 'held')->count());

        // Finance confirms the cash payment
        $finance = $this->makeUser($tenant, 'finance_officer');
        $this->actingAs($finance, 'sanctum');
        $this->postJson("/api/v1/payments/{$booking->openPayment->id}/confirm")->assertOk();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertEquals(4, TripSeatLeg::where('booking_id', $booking->id)->where('state', 'booked')->count());

        // Tickets issued + QR verify works (agent has tickets.verify)
        app(\App\Services\TicketService::class)->issueFor($booking);
        $ticket = $booking->tickets()->first();
        $this->assertSame('valid', $ticket->status);

        $agent = $this->makeUser($tenant, 'booking_agent');
        $this->actingAs($agent, 'sanctum');
        $this->postJson('/api/v1/tickets/verify', ['qr' => $ticket->qrPayload(), 'mark_used' => true])
            ->assertOk()->assertJsonPath('ticket.status', 'used');

        // Second scan reports already-used, never re-validates
        $this->postJson('/api/v1/tickets/verify', ['qr' => $ticket->qrPayload(), 'mark_used' => true])
            ->assertJsonPath('already_used', true);
    }

    public function test_expired_holds_release_inventory(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip))->assertCreated();

        $held = TripSeatLeg::where('state', 'held')->count();
        $this->assertGreaterThan(0, $held);

        $this->travel(11)->minutes(); // default seat_hold_minutes = 10
        app(\App\Services\SeatInventoryService::class)->releaseExpiredHolds();

        $this->assertEquals(0, TripSeatLeg::where('state', 'held')->count());
    }

    public function test_guest_cannot_read_booking_without_contact_key(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);
        $ref = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip))
            ->json('data.reference');

        $this->publicGet($tenant, "/api/v1/public/bookings/{$ref}")->assertForbidden();
        $this->publicGet($tenant, "/api/v1/public/bookings/{$ref}?email=pax@example.com")->assertOk();
        $this->publicGet($tenant, "/api/v1/public/bookings/{$ref}?email=wrong@example.com")->assertForbidden();
    }

    public function test_booking_idempotency_key_prevents_duplicates(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $headers = ['Idempotency-Key' => 'same-key-123'];
        $first = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip), $headers);
        $second = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip), $headers);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertEquals(1, Booking::count());
    }

    public function test_cannot_book_more_than_max_seats(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        $payload = $this->bookingPayload($trip, [
            'passengers' => array_fill(0, 11, ['first_name' => 'P', 'last_name' => 'X']),
        ]);

        $this->publicPost($tenant, '/api/v1/public/bookings', $payload)->assertStatus(422);
    }

    public function test_segment_booking_reuses_seat_on_disjoint_leg(): void
    {
        $tenant = $this->makeTenant();
        $trip = $this->makeTrip($tenant);

        // Passenger A books seat 1 for legs 0→1 (Harare→Kwekwe)
        $seat = \App\Models\TripSeat::where('trip_id', $trip->id)->where('label', '1')->first();
        $stopB = $trip->route->stops()->where('sequence', 1)->first();

        $a = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, [
            'dropoff_stop_id' => $stopB->id, 'dropoff_sequence' => 1,
            'trip_seat_ids' => [$seat->id],
            'payment_method' => 'cash',
        ]));
        $a->assertCreated();
        $bookingA = Booking::findOrFail($a->json('data.id'));

        $finance = $this->makeUser($tenant, 'finance_officer');
        $this->actingAs($finance, 'sanctum');
        $this->postJson("/api/v1/payments/{$bookingA->openPayment->id}/confirm")->assertOk();

        // Passenger B books THE SAME seat for legs 1→2 (Kwekwe→Bulawayo) — disjoint.
        $stopC = $trip->route->stops()->where('sequence', 2)->first();
        $b = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, [
            'boarding_stop_id' => $stopB->id, 'boarding_sequence' => 1,
            'dropoff_stop_id' => $stopC->id, 'dropoff_sequence' => 2,
            'trip_seat_ids' => [$seat->id],
            'payment_method' => 'cash',
        ]));
        $b->assertCreated(); // segment reuse works

        // But overlapping (0→2) on the same seat must 409.
        $c = $this->publicPost($tenant, '/api/v1/public/bookings', $this->bookingPayload($trip, [
            'trip_seat_ids' => [$seat->id],
        ]));
        $c->assertStatus(409);
    }
}
