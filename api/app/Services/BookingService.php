<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\User;
use App\StateMachines\BookingStateMachine;
use App\Support\Audit;
use App\Support\BookingReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private SeatInventoryService $seats,
        private AutoAllocator $allocator,
        private PricingService $pricing,
        private PromotionService $promotions,
        private BookingStateMachine $states,
        private RefundCalculator $refunds,
    ) {}

    /**
     * Create a booking: validate → hold seats → price → promo → persist.
     * All inside one transaction; seat hold is row-locked (SEAT_ENGINE.md).
     *
     * @param  array  $data  Validated request payload.
     */
    public function create(array $data, ?User $creator = null): Booking
    {
        return DB::transaction(function () use ($data, $creator) {
            $trip = Trip::with(['route.stops', 'bus.seatLayout'])->lockForUpdate()->findOrFail($data['trip_id']);

            if (! $trip->isBookable()) {
                throw ValidationException::withMessages(['trip_id' => 'This trip is not available for booking.']);
            }

            $cutoff = (int) $trip->tenant->bookingRule('booking_cutoff_minutes', 60);
            if ($trip->departure_at->diffInMinutes(now(), false) > -$cutoff) {
                throw ValidationException::withMessages(['trip_id' => 'Booking has closed for this trip.']);
            }

            $boardingSeq = (int) $data['boarding_sequence'];
            $dropoffSeq = (int) $data['dropoff_sequence'];
            $this->validateStops($trip, $boardingSeq, $dropoffSeq);

            $passengers = $data['passengers'];
            $max = (int) $trip->tenant->bookingRule('max_seats_per_booking', 10);
            if (count($passengers) > $max) {
                throw ValidationException::withMessages(['passengers' => "Maximum {$max} passengers per booking."]);
            }

            // Resolve seats: explicit ids or auto-allocation.
            $seatIds = $data['trip_seat_ids'] ?? null;
            if (empty($seatIds)) {
                $seatIds = $this->allocator->allocate(
                    $trip,
                    count($passengers),
                    $data['seat_preference'] ?? 'together',
                    $boardingSeq,
                    $dropoffSeq,
                );
                if (count($seatIds) < count($passengers)) {
                    throw new SeatUnavailableException('Not enough seats are available for this selection.');
                }
            }

            if (count($seatIds) !== count($passengers)) {
                throw ValidationException::withMessages(['trip_seat_ids' => 'Seat count must match passenger count.']);
            }

            $seats = TripSeat::where('trip_id', $trip->id)->whereIn('id', $seatIds)->get();

            $quote = $this->pricing->quote($trip, $seats, $passengers, $boardingSeq, $dropoffSeq);

            $ticketType = $data['ticket_type'] ?? 'dated';
            if ($ticketType === 'open') {
                $multiplier = $this->pricing->openFareMultiplier($trip->tenant);
                $quote['subtotal'] = round($quote['subtotal'] * $multiplier, 2);
                $quote['total_before_discount'] = $quote['subtotal'];
            }

            $discount = 0.0;
            $promotion = null;
            if (! empty($data['promotion_code'])) {
                $promotion = $this->promotions->resolve(
                    $data['promotion_code'], $trip, $quote['subtotal'],
                    $data['contact_email'] ?? $data['contact_phone'] ?? null,
                );
                $discount = $promotion->discountFor($quote['subtotal']);
            }

            $booking = Booking::create([
                'trip_id' => $trip->id,
                'user_id' => $creator?->id,
                'booking_reference' => BookingReference::generate(),
                'status' => Booking::STATUS_HELD,
                'subtotal' => $quote['subtotal'],
                'discount_amount' => $discount,
                'total' => round($quote['subtotal'] - $discount, 2),
                'currency' => $quote['currency'],
                'promotion_code' => $promotion?->code,
                'promotion_id' => $promotion?->id,
                'payment_method' => $data['payment_method'] ?? null,
                'ticket_type' => $ticketType,
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'],
                'boarding_stop_id' => $data['boarding_stop_id'],
                'dropoff_stop_id' => $data['dropoff_stop_id'],
                'boarding_sequence' => $boardingSeq,
                'dropoff_sequence' => $dropoffSeq,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'pricing_snapshot' => $quote,
                'expires_at' => now()->addMinutes((int) $trip->tenant->bookingRule('seat_hold_minutes', 10)),
            ]);

            // Hold seats under row lock — authoritative concurrency check.
            $this->seats->hold($trip, $seatIds, $boardingSeq, $dropoffSeq, $booking);

            $seatByIndex = $seats->sortBy(fn ($s) => array_search($s->id, $seatIds))->values();

            foreach ($passengers as $i => $pax) {
                $bookingPassenger = $booking->passengers()->create([
                    'tenant_id' => $trip->tenant_id,
                    'passenger_id' => $this->linkPassenger($pax),
                    'first_name' => $pax['first_name'],
                    'last_name' => $pax['last_name'],
                    'email' => $pax['email'] ?? null,
                    'phone' => $pax['phone'] ?? null,
                    'id_document' => $pax['id_document'] ?? null,
                    'date_of_birth' => $pax['date_of_birth'] ?? null,
                    'gender' => $pax['gender'] ?? null,
                    'passenger_type' => $pax['passenger_type'] ?? 'adult',
                    'emergency_contact' => $pax['emergency_contact'] ?? null,
                    'special_requirements' => $pax['special_requirements'] ?? null,
                ]);

                $seat = $seatByIndex[$i];
                $booking->seats()->create([
                    'tenant_id' => $trip->tenant_id,
                    'trip_seat_id' => $seat->id,
                    'booking_passenger_id' => $bookingPassenger->id,
                    'seat_label' => $seat->label,
                    'fare' => $quote['breakdown'][$i]['amount'] ?? 0,
                    'boarding_sequence' => $boardingSeq,
                    'dropoff_sequence' => $dropoffSeq,
                    'status' => 'active',
                ]);
            }

            if ($promotion) {
                $this->promotions->redeem($promotion, $booking, $discount, $booking->contact_email ?? $booking->contact_phone);
            }

            $booking->logEvent('created', $creator?->id, $creator ? 'user' : 'guest', [
                'seats' => $seatByIndex->pluck('label')->all(),
                'total' => $booking->total,
            ]);

            Audit::record('booking.created', $booking, [
                'reference' => $booking->booking_reference,
                'seats' => $seatByIndex->pluck('label')->all(),
            ]);

            return $booking->fresh(['passengers', 'seats.tripSeat', 'trip.route.stops']);
        });
    }

    /**
     * Move a held booking to pending_payment once a payment is initiated.
     */
    public function markPendingPayment(Booking $booking, ?User $actor = null): Booking
    {
        return $this->states->transition($booking, Booking::STATUS_PENDING_PAYMENT, $actor?->id, 'user');
    }

    /**
     * Confirm booking once payment succeeds. Re-validates seat inventory —
     * an expired hold that lost its seat raises SeatUnavailableException and
     * the payment is NOT silently confirmed (payment ops reconcile path).
     */
    public function confirm(Booking $booking, ?User $actor = null): Booking
    {
        return DB::transaction(function () use ($booking, $actor) {
            $booking->refresh();
            $this->seats->confirmFor($booking);
            $this->states->transition($booking, Booking::STATUS_CONFIRMED, $actor?->id, $actor ? 'user' : 'system');
            $booking->logEvent('confirmed', $actor?->id, $actor ? 'user' : 'system');

            return $booking;
        });
    }

    public function cancel(Booking $booking, ?User $actor = null, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $actor, $reason) {
            $booking->refresh();

            $this->seats->releaseFor($booking);
            $this->states->transition($booking, Booking::STATUS_CANCELLED, $actor?->id, $actor ? 'user' : 'system', ['reason' => $reason]);
            $booking->update(['cancellation_reason' => $reason]);

            if ($booking->promotion_id) {
                $this->promotions->reverseFor($booking);
            }

            $booking->tickets()->update(['status' => Ticket::STATUS_CANCELLED]);

            $booking->logEvent('cancelled', $actor?->id, $actor ? 'user' : 'system', ['reason' => $reason]);
            Audit::record('booking.cancelled', $booking, ['reason' => $reason]);

            return $booking;
        });
    }

    /** Held/pending booking timed out — release inventory, mark expired. */
    public function expire(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $booking->refresh();
            if (! in_array($booking->status, [Booking::STATUS_HELD, Booking::STATUS_PENDING_PAYMENT], true)) {
                return $booking;
            }

            $this->seats->releaseFor($booking);
            $this->states->transition($booking, Booking::STATUS_EXPIRED);
            if ($booking->promotion_id) {
                $this->promotions->reverseFor($booking);
            }
            $booking->logEvent('expired');

            return $booking;
        });
    }

    /** Sweep: held/pending bookings past expiry — release seats + mark expired. */
    public function expireStaleBookings(): int
    {
        $stale = Booking::withoutTenantScope()
            ->whereIn('status', [Booking::STATUS_HELD, Booking::STATUS_PENDING_PAYMENT])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($stale as $booking) {
            try {
                $this->expire($booking);
            } catch (\Throwable) {
                // Invalid transition races are safe — job is idempotent.
            }
        }

        return $stale->count();
    }

    /** Refund amount owed for a cancellation, per tenant policy. */
    public function refundDue(Booking $booking): float
    {
        return $this->refunds->forCancellation($booking);
    }

    private function validateStops(Trip $trip, int $boardingSeq, int $dropoffSeq): void
    {
        $stops = $trip->route->stops;
        $boarding = $stops->firstWhere('sequence', $boardingSeq);
        $dropoff = $stops->firstWhere('sequence', $dropoffSeq);

        if (! $boarding || ! $boarding->can_board) {
            throw ValidationException::withMessages(['boarding_stop_id' => 'Invalid boarding point.']);
        }
        if (! $dropoff || ! $dropoff->can_alight || $dropoffSeq <= $boardingSeq) {
            throw ValidationException::withMessages(['dropoff_stop_id' => 'Invalid drop-off point.']);
        }
    }

    private function linkPassenger(array $pax): ?int
    {
        $email = $pax['email'] ?? null;
        $phone = $pax['phone'] ?? null;
        if (! $email && ! $phone) {
            return null;
        }

        $existing = Passenger::query()
            ->when($email, fn ($q) => $q->where('email', $email))
            ->when(! $email && $phone, fn ($q) => $q->where('phone', $phone))
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return Passenger::create([
            'first_name' => $pax['first_name'],
            'last_name' => $pax['last_name'],
            'email' => $email,
            'phone' => $phone,
            'id_document' => $pax['id_document'] ?? null,
        ])->id;
    }
}
