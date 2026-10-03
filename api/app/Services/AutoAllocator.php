<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripSeat;
use App\Models\TripSeatLeg;
use Illuminate\Support\Collection;

/**
 * Automatic seat assignment. Only ever returns seats that are free for the
 * whole requested segment; the subsequent hold() still performs the
 * authoritative locked check, so the allocator never causes a double-book.
 */
class AutoAllocator
{
    /**
     * @return list<int> trip_seat ids
     */
    public function allocate(Trip $trip, int $count, string $preference, int $boardingSeq, int $dropoffSeq): array
    {
        $legIndexes = range($boardingSeq, $dropoffSeq - 1);

        $freeSeatIds = TripSeatLeg::where('trip_id', $trip->id)
            ->whereIn('leg_index', $legIndexes)
            ->where(fn ($q) => $q
                ->where('state', TripSeatLeg::STATE_AVAILABLE)
                ->orWhere(fn ($q2) => $q2
                    ->where('state', TripSeatLeg::STATE_HELD)
                    ->where('held_until', '<=', now())))
            ->groupBy('trip_seat_id')
            ->havingRaw('count(*) = ?', [count($legIndexes)])
            ->pluck('trip_seat_id');

        $seats = TripSeat::where('trip_id', $trip->id)
            ->where('state', TripSeat::STATE_AVAILABLE)
            ->whereIn('id', $freeSeatIds)
            ->get()
            ->filter(fn (TripSeat $s) => $s->isBookableType())
            ->values();

        if ($seats->count() < $count) {
            return [];
        }

        $seats = match ($preference) {
            'window' => $this->preferPosition($seats, 'window'),
            'aisle' => $this->preferPosition($seats, 'aisle'),
            'rear' => $seats->sortByDesc(['row', 'col'])->values(),
            default => $seats->sortBy(['deck', 'row', 'col'])->values(), // front/together/any
        };

        if ($preference === 'together' || $preference === 'any' || $preference === 'front') {
            $grouped = $seats->groupBy(fn (TripSeat $s) => $s->deck.':'.$s->row);
            foreach ($grouped as $rowSeats) {
                if ($rowSeats->count() >= $count) {
                    return $rowSeats->sortBy('col')->take($count)->pluck('id')->all();
                }
            }
        }

        return $seats->take($count)->pluck('id')->all();
    }

    private function preferPosition(Collection $seats, string $position): Collection
    {
        $preferred = $seats->where('position', $position)->sortBy(['deck', 'row', 'col']);
        if ($preferred->count() >= 1) {
            return $preferred->merge($seats->where('position', '!=', $position)->sortBy(['deck', 'row', 'col']))->values();
        }

        return $seats->sortBy(['deck', 'row', 'col'])->values();
    }
}
