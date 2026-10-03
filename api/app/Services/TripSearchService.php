<?php

namespace App\Services;

use App\Models\BusRoute;
use App\Models\RouteStop;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Passenger-facing trip search: from/to stop names + departure date.
 * Availability uses the leg-aware seat inventory.
 */
class TripSearchService
{
    public function __construct(private SeatInventoryService $seats) {}

    public function search(string $from, string $to, string $date, int $passengers): Collection
    {
        $day = Carbon::parse($date, tenant()->timezone ?? 'UTC');

        $routes = BusRoute::where('status', 'active')
            ->whereHas('stops', fn ($q) => $q->where('can_board', true)->where('name', 'like', "%{$from}%"))
            ->whereHas('stops', fn ($q) => $q->where('can_alight', true)->where('name', 'like', "%{$to}%"))
            ->with(['stops'])
            ->get()
            ->filter(function (BusRoute $route) use ($from, $to) {
                $boarding = $route->stops->filter(fn ($s) => $s->can_board && str_contains(strtolower($s->name), strtolower($from)))->first();
                $dropoff = $route->stops->filter(fn ($s) => $s->can_alight && str_contains(strtolower($s->name), strtolower($to)))->first();

                return $boarding && $dropoff && $boarding->sequence < $dropoff->sequence;
            });

        return Trip::whereIn('route_id', $routes->pluck('id'))
            ->whereIn('status', Trip::BOOKABLE_STATUSES)
            ->whereBetween('departure_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->with(['route.stops', 'bus'])
            ->get()
            ->map(function (Trip $trip) use ($from, $to, $passengers) {
                $boarding = $trip->route->stops->filter(fn ($s) => $s->can_board && str_contains(strtolower($s->name), strtolower($from)))->first();
                $dropoff = $trip->route->stops->filter(fn ($s) => $s->can_alight && str_contains(strtolower($s->name), strtolower($to)))->first();
                $available = $this->seats->availableCount($trip, $boarding->sequence, $dropoff->sequence);

                $fare = $trip->route->fareFor($boarding->sequence, $dropoff->sequence, 'adult');

                return [
                    'trip' => $trip,
                    'boarding_stop' => $boarding,
                    'dropoff_stop' => $dropoff,
                    'available_seats' => $available,
                    'fare' => $fare ? (float) $fare->amount : (float) $trip->base_fare,
                    'bookable' => $available >= $passengers && $trip->isBookable(),
                ];
            })
            ->values();
    }

    /** Distinct origin/destination names for the search autocomplete. */
    public function endpoints(): array
    {
        $stops = RouteStop::whereHas('route', fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')->get(['name', 'can_board', 'can_alight']);

        return [
            'origins' => $stops->where('can_board', true)->pluck('name')->unique()->values(),
            'destinations' => $stops->where('can_alight', true)->pluck('name')->unique()->values(),
        ];
    }
}
