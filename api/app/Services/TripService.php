<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Schedule;
use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\TripSeatLeg;
use App\Models\User;
use App\StateMachines\BookingStateMachine;
use App\Support\Audit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function __construct(
        private SeatInventoryService $seats,
        private BookingService $bookings,
        private BookingStateMachine $bookingStates,
        private AutoAllocator $allocator,
    ) {}

    /**
     * Materialise trip instances for a schedule across a date window.
     * Idempotent — skips dates that already have a trip for the schedule.
     */
    public function generateFromSchedule(Schedule $schedule, CarbonInterface $from, CarbonInterface $to): int
    {
        $created = 0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! $schedule->runsOn($date)) {
                continue;
            }

            $departure = Carbon::parse($date->toDateString().' '.$schedule->departure_time, $schedule->tenant->timezone)->utc();

            $exists = Trip::where('schedule_id', $schedule->id)
                ->whereDate('departure_at', $departure->toDateString())
                ->exists();
            if ($exists) {
                continue;
            }

            $duration = $schedule->route->duration_minutes ?? 0;

            $trip = Trip::create([
                'schedule_id' => $schedule->id,
                'route_id' => $schedule->route_id,
                'bus_id' => $schedule->bus_id,
                'departure_at' => $departure,
                'arrival_estimate_at' => $departure->copy()->addMinutes($duration),
                'base_fare' => $schedule->fare_override ?? $schedule->route->base_fare,
                'status' => Trip::STATUS_SCHEDULED,
            ]);

            $this->seats->materialize($trip);
            $created++;
        }

        return $created;
    }

    /** Publish a single ad-hoc trip. */
    public function create(array $data): Trip
    {
        return DB::transaction(function () use ($data) {
            $route = BusRoute::findOrFail($data['route_id']);
            $bus = Bus::findOrFail($data['bus_id']);

            $departure = Carbon::parse($data['departure_at'], tenant()->timezone)->utc();

            $trip = Trip::create([
                'schedule_id' => $data['schedule_id'] ?? null,
                'route_id' => $route->id,
                'bus_id' => $bus->id,
                'driver_name' => $data['driver_name'] ?? null,
                'departure_at' => $departure,
                'arrival_estimate_at' => $departure->copy()->addMinutes($route->duration_minutes ?? 0),
                'base_fare' => $data['base_fare'] ?? $route->base_fare,
                'status' => Trip::STATUS_SCHEDULED,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->seats->materialize($trip);
            Audit::record('trip.created', $trip);

            return $trip;
        });
    }

    public function transitionStatus(Trip $trip, string $status, array $extra = [], ?User $actor = null): Trip
    {
        $allowed = [Trip::STATUS_BOARDING, Trip::STATUS_DEPARTED, Trip::STATUS_IN_TRANSIT,
            Trip::STATUS_ARRIVED, Trip::STATUS_DELAYED, Trip::STATUS_CANCELLED, Trip::STATUS_COMPLETED];

        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid trip status.']);
        }
        if ($trip->status === Trip::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['status' => 'A cancelled trip cannot change status.']);
        }

        return DB::transaction(function () use ($trip, $status, $extra, $actor) {
            if ($status === Trip::STATUS_CANCELLED) {
                $trip->update([
                    'status' => $status,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $extra['reason'] ?? null,
                ]);
                $this->cancelAllBookings($trip, $extra['reason'] ?? 'Trip cancelled by operator');
            } elseif ($status === Trip::STATUS_DELAYED) {
                $trip->update(['status' => $status, 'delayed_until' => $extra['delayed_until'] ?? null]);
            } else {
                $trip->update(['status' => $status]);
                if ($status === Trip::STATUS_COMPLETED || $status === Trip::STATUS_ARRIVED) {
                    $this->completeConfirmedBookings($trip);
                }
            }

            Audit::record("trip.{$status}", $trip, $extra, $actor?->id);

            return $trip->fresh();
        });
    }

    /** Swap the bus: re-materialise inventory and re-seat booked pax. */
    public function swapBus(Trip $trip, Bus $newBus): Trip
    {
        return DB::transaction(function () use ($trip, $newBus) {
            $oldSeats = $trip->seats()->get()->keyBy('label');

            $trip->update(['bus_id' => $newBus->id]);

            // Remove old inventory for legs not yet consumed by bookings.
            $bookedLegs = $trip->seats()
                ->whereHas('legs', fn ($q) => $q->where('state', TripSeatLeg::STATE_BOOKED))
                ->pluck('id');
            TripSeat::where('trip_id', $trip->id)
                ->whereNotIn('id', $bookedLegs)->delete();
            TripSeatLeg::where('trip_id', $trip->id)->whereNull('booking_id')->delete();

            // Materialise new layout seats that don't exist yet.
            $newBus->loadMissing('seatLayout.seats');
            $existingSeatIds = $trip->seats()->pluck('seat_id');
            foreach ($newBus->seatLayout->seats as $seat) {
                if ($existingSeatIds->contains($seat->id)) {
                    continue;
                }
                $tripSeat = TripSeat::create([
                    'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id, 'seat_id' => $seat->id,
                    'deck' => $seat->deck, 'row' => $seat->row, 'col' => $seat->col,
                    'label' => $seat->label, 'type' => $seat->type, 'position' => $seat->position,
                    'side' => $seat->side,
                    'price_modifier' => $seat->price_modifier,
                ]);
                $legCount = max(1, $trip->route->stops->count() - 1);
                for ($leg = 0; $leg < $legCount; $leg++) {
                    TripSeatLeg::create([
                        'tenant_id' => $trip->tenant_id, 'trip_id' => $trip->id,
                        'trip_seat_id' => $tripSeat->id, 'leg_index' => $leg,
                    ]);
                }
            }

            // Re-seat passengers whose seat label exists on the new layout.
            $newByLabel = $trip->fresh()->seats->keyBy('label');
            foreach ($trip->bookings()->whereIn('status', Booking::ACTIVE_STATUSES)->with('seats')->get() as $booking) {
                foreach ($booking->seats()->where('status', 'active')->get() as $bs) {
                    $match = $newByLabel->get($bs->seat_label);
                    if (! $match) {
                        $match = $this->allocator->allocate($trip, 1, 'any', $booking->boarding_sequence, $booking->dropoff_sequence);
                        $match = $match ? TripSeat::find($match[0]) : null;
                    }
                    if ($match && $match->id !== $bs->trip_seat_id) {
                        TripSeatLeg::where('trip_seat_id', $bs->trip_seat_id)
                            ->whereBetween('leg_index', [$bs->boarding_sequence, $bs->dropoff_sequence - 1])
                            ->where('booking_id', $booking->id)
                            ->update(['state' => 'available', 'booking_id' => null, 'held_until' => null, 'hold_token' => null]);

                        $bs->update(['trip_seat_id' => $match->id, 'seat_label' => $match->label]);
                        TripSeatLeg::where('trip_seat_id', $match->id)
                            ->whereBetween('leg_index', [$bs->boarding_sequence, $bs->dropoff_sequence - 1])
                            ->update(['state' => 'booked', 'booking_id' => $booking->id, 'held_until' => null, 'hold_token' => null]);

                        $booking->logEvent('seat_reassigned', metadata: ['to' => $match->label]);
                    }
                }
            }

            Audit::record('trip.bus_swapped', $trip, ['new_bus' => $newBus->id]);

            return $trip->fresh();
        });
    }

    private function cancelAllBookings(Trip $trip, string $reason): void
    {
        foreach ($trip->bookings()->whereIn('status', Booking::ACTIVE_STATUSES)->get() as $booking) {
            $this->bookings->cancel($booking, null, $reason);
        }
    }

    private function completeConfirmedBookings(Trip $trip): void
    {
        foreach ($trip->bookings()->where('status', Booking::STATUS_CONFIRMED)->get() as $booking) {
            if ($this->bookingStates->canTransition($booking, Booking::STATUS_COMPLETED)) {
                $this->bookingStates->transition($booking, Booking::STATUS_COMPLETED);
            }
        }
    }
}
