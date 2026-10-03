<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\SeatLayoutSeat;
use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\TripSeatLeg;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns all seat-inventory mutations. Every write goes through row-level
 * locks on trip_seat_legs inside a transaction — callers must wrap in
 * DB::transaction (hold()/confirm() do so themselves).
 */
class SeatInventoryService
{
    /**
     * Materialise seat inventory for a trip from its bus's seat layout.
     * Creates one trip_seat per layout seat plus one leg row per route leg.
     */
    public function materialize(Trip $trip): void
    {
        $trip->loadMissing(['bus.seatLayout.seats', 'route.stops']);

        $layout = $trip->bus?->seatLayout;
        $legCount = max(1, $trip->route->stops->count() - 1);

        if (! $layout || $layout->seats->isEmpty()) {
            throw new \DomainException('Cannot publish a trip whose bus has no seat layout.');
        }

        DB::transaction(function () use ($trip, $layout, $legCount) {
            $now = now();
            $legRows = [];

            foreach ($layout->seats->sortBy('sort_order') as $seat) {
                $tripSeat = TripSeat::create([
                    'tenant_id' => $trip->tenant_id,
                    'trip_id' => $trip->id,
                    'seat_id' => $seat->id,
                    'deck' => $seat->deck,
                    'row' => $seat->row,
                    'col' => $seat->col,
                    'label' => $seat->label,
                    'type' => $seat->type,
                    'position' => $seat->position,
                    'side' => $seat->side,
                    'price_modifier' => $seat->price_modifier,
                    'state' => TripSeat::STATE_AVAILABLE,
                ]);

                if ($tripSeat->isBookableType()) {
                    for ($leg = 0; $leg < $legCount; $leg++) {
                        $legRows[] = [
                            'tenant_id' => $trip->tenant_id,
                            'trip_id' => $trip->id,
                            'trip_seat_id' => $tripSeat->id,
                            'leg_index' => $leg,
                            'state' => TripSeatLeg::STATE_AVAILABLE,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            foreach (array_chunk($legRows, 500) as $chunk) {
                TripSeatLeg::insert($chunk);
            }
        });
    }

    /**
     * Seat map for display on a [boardingSeq, dropoffSeq) segment.
     * Expired holds are treated as available (lazy release).
     */
    public function seatMap(Trip $trip, int $boardingSeq, int $dropoffSeq): Collection
    {
        $legIndexes = range($boardingSeq, $dropoffSeq - 1);

        $seats = TripSeat::where('trip_id', $trip->id)
            ->orderBy('deck')->orderBy('row')->orderBy('col')
            ->get();

        $legs = TripSeatLeg::where('trip_id', $trip->id)
            ->whereIn('leg_index', $legIndexes)
            ->get()
            ->groupBy('trip_seat_id');

        return $seats->map(function (TripSeat $seat) use ($legs, $legIndexes) {
            $seatLegs = $legs->get($seat->id, collect());

            $state = match (true) {
                ! $seat->isBookableType() => 'unavailable',
                ! $seat->isOperatorAvailable() => $seat->state, // blocked / maintenance
                $seatLegs->isEmpty() => 'unavailable',
                $seatLegs->where('state', TripSeatLeg::STATE_BOOKED)->isNotEmpty() => 'booked',
                $seatLegs->filter(fn (TripSeatLeg $l) => $l->state === TripSeatLeg::STATE_HELD
                    && ($l->held_until === null || $l->held_until->isFuture()))->isNotEmpty() => 'held',
                $seatLegs->count() < count($legIndexes) => 'unavailable',
                default => 'available',
            };

            return [
                'id' => $seat->id,
                'label' => $seat->label,
                'deck' => $seat->deck,
                'row' => $seat->row,
                'col' => $seat->col,
                'type' => $seat->type,
                'position' => $seat->position,
                'side' => $seat->side,
                'price_modifier' => (float) $seat->price_modifier,
                'bookable' => $seat->isBookableType(),
                'state' => $state,
            ];
        });
    }

    /**
     * Hold seats for a segment inside the caller's transaction.
     *
     * @param  list<int>  $tripSeatIds
     */
    public function hold(Trip $trip, array $tripSeatIds, int $boardingSeq, int $dropoffSeq, ?Booking $booking = null, ?string $holdToken = null): string
    {
        $holdToken ??= Str::random(24);
        $legIndexes = range($boardingSeq, $dropoffSeq - 1);

        $seats = TripSeat::where('trip_id', $trip->id)
            ->whereIn('id', $tripSeatIds)
            ->lockForUpdate()
            ->get();

        if ($seats->count() !== count(array_unique($tripSeatIds))) {
            throw new SeatUnavailableException;
        }

        foreach ($seats as $seat) {
            if (! $seat->isBookableType() || ! $seat->isOperatorAvailable()) {
                throw new SeatUnavailableException("Seat {$seat->label} is not available.");
            }
        }

        $legs = TripSeatLeg::whereIn('trip_seat_id', $tripSeatIds)
            ->whereIn('leg_index', $legIndexes)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($legs->count() !== count($tripSeatIds) * count($legIndexes)) {
            throw new SeatUnavailableException;
        }

        foreach ($legs as $leg) {
            $expired = $leg->state === TripSeatLeg::STATE_HELD
                && ($leg->held_until === null || $leg->held_until->isPast());

            $ours = $leg->state === TripSeatLeg::STATE_HELD && $booking && $leg->booking_id === $booking->id;

            if (! ($leg->state === TripSeatLeg::STATE_AVAILABLE || $expired || $ours)) {
                $label = (string) ($seats->firstWhere('id', $leg->trip_seat_id)->label ?? '?');
                throw new SeatUnavailableException("Seat {$label} is no longer available.");
            }
        }

        $ttl = (int) ($trip->tenant->bookingRule('seat_hold_minutes', 10));
        TripSeatLeg::whereIn('id', $legs->pluck('id'))->update([
            'state' => TripSeatLeg::STATE_HELD,
            'held_until' => now()->addMinutes($ttl),
            'hold_token' => $holdToken,
            'booking_id' => $booking?->id,
        ]);

        return $holdToken;
    }

    /**
     * Convert a booking's held legs to booked (payment confirmed / cash
     * reserved per policy). Re-takes seats whose hold expired but are still
     * free; throws if another booking took them meanwhile.
     */
    public function confirmFor(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $seatIds = $booking->seats()->pluck('trip_seat_id')->all();
            $legIndexes = range($booking->boarding_sequence, $booking->dropoff_sequence - 1);

            $legs = TripSeatLeg::whereIn('trip_seat_id', $seatIds)
                ->whereIn('leg_index', $legIndexes)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($legs as $leg) {
                $ok = match (true) {
                    $leg->state === TripSeatLeg::STATE_BOOKED && $leg->booking_id === $booking->id => true, // idempotent
                    $leg->state === TripSeatLeg::STATE_AVAILABLE => true, // expired-hold retake
                    $leg->state === TripSeatLeg::STATE_HELD && ($leg->booking_id === $booking->id
                        || $leg->held_until === null || $leg->held_until->isPast()) => true,
                    default => false,
                };
                if (! $ok) {
                    throw new SeatUnavailableException('A held seat was taken by another booking.');
                }
            }

            TripSeatLeg::whereIn('id', $legs->pluck('id'))->update([
                'state' => TripSeatLeg::STATE_BOOKED,
                'held_until' => null,
                'hold_token' => null,
                'booking_id' => $booking->id,
            ]);
        });
    }

    /** Release a booking's seats (cancel/expire) — held or booked. */
    public function releaseFor(Booking $booking): void
    {
        $seatIds = $booking->seats()->pluck('trip_seat_id')->all();
        $legIndexes = range($booking->boarding_sequence, $booking->dropoff_sequence - 1);

        TripSeatLeg::whereIn('trip_seat_id', $seatIds)
            ->whereIn('leg_index', $legIndexes)
            ->where('booking_id', $booking->id)
            ->update([
                'state' => TripSeatLeg::STATE_AVAILABLE,
                'held_until' => null,
                'hold_token' => null,
                'booking_id' => null,
            ]);

        $booking->seats()->update(['status' => 'released']);
    }

    /** Scheduled sweep — release all holds whose TTL elapsed. */
    public function releaseExpiredHolds(): int
    {
        $expiredLegs = TripSeatLeg::withoutTenantScope()
            ->where('state', TripSeatLeg::STATE_HELD)
            ->where('held_until', '<=', now())
            ->get(['id', 'booking_id', 'trip_seat_id']);

        if ($expiredLegs->isEmpty()) {
            return 0;
        }

        TripSeatLeg::withoutTenantScope()
            ->whereIn('id', $expiredLegs->pluck('id'))
            ->update(['state' => TripSeatLeg::STATE_AVAILABLE, 'held_until' => null, 'hold_token' => null, 'booking_id' => null]);

        return $expiredLegs->count();
    }

    /** Free seat count for a segment — used by search results. */
    public function availableCount(Trip $trip, int $boardingSeq, int $dropoffSeq): int
    {
        $legIndexes = range($boardingSeq, $dropoffSeq - 1);

        return TripSeatLeg::where('trip_id', $trip->id)
            ->whereIn('leg_index', $legIndexes)
            ->whereHas('tripSeat', fn ($q) => $q
                ->where('state', TripSeat::STATE_AVAILABLE)
                ->whereNotIn('type', SeatLayoutSeat::NON_BOOKABLE_TYPES))
            ->where(fn ($q) => $q
                ->where('state', TripSeatLeg::STATE_AVAILABLE)
                ->orWhere(fn ($q2) => $q2
                    ->where('state', TripSeatLeg::STATE_HELD)
                    ->where('held_until', '<=', now())))
            ->groupBy('trip_seat_id')
            ->havingRaw('count(*) = ?', [count($legIndexes)])
            ->pluck('trip_seat_id')
            ->count();
    }
}
