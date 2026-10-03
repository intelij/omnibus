<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRouteRequest;
use App\Models\BusRoute;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', BusRoute::class);

        return response()->json(
            BusRoute::withCount('stops')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->q, fn ($q, $v) => $q->where(fn ($q2) => $q2
                    ->where('name', 'like', "%{$v}%")->orWhere('origin', 'like', "%{$v}%")->orWhere('destination', 'like', "%{$v}%")))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(StoreRouteRequest $request)
    {
        $this->authorize('create', BusRoute::class);

        $data = $request->validated();

        $route = DB::transaction(function () use ($data) {
            $route = BusRoute::create(collect($data)->except(['stops', 'fares'])->all());
            $this->persistStopsAndFares($route, $data);

            return $route;
        });

        Audit::record('route.created', $route);

        return response()->json($route->load(['stops', 'fares']), 201);
    }

    public function show(BusRoute $route)
    {
        $this->authorize('view', $route);

        return response()->json($route->load(['stops', 'fares']));
    }

    public function update(StoreRouteRequest $request, BusRoute $route)
    {
        $this->authorize('update', $route);

        $data = $request->validated();

        DB::transaction(function () use ($route, $data) {
            $route->update(collect($data)->except(['stops', 'fares'])->all());
            $route->stops()->delete();
            $route->fares()->delete();
            $this->persistStopsAndFares($route, $data);
        });

        Audit::record('route.updated', $route);

        return response()->json($route->load(['stops', 'fares']));
    }

    public function destroy(BusRoute $route)
    {
        $this->authorize('delete', $route);

        if ($route->trips()->where('departure_at', '>', now())->exists()) {
            return response()->json(['message' => 'Route has upcoming trips — set it inactive instead.'], 422);
        }

        $route->delete();
        Audit::record('route.deleted', $route);

        return response()->json(['message' => 'Route deleted.']);
    }

    private function persistStopsAndFares(BusRoute $route, array $data): void
    {
        foreach ($data['stops'] ?? [] as $stop) {
            $route->stops()->create([
                'tenant_id' => $route->tenant_id,
                'name' => $stop['name'],
                'location_id' => $stop['location_id'] ?? null,
                'sequence' => $stop['sequence'],
                'can_board' => $stop['can_board'] ?? true,
                'can_alight' => $stop['can_alight'] ?? true,
                'offset_minutes' => $stop['offset_minutes'] ?? 0,
            ]);
        }

        foreach ($data['fares'] ?? [] as $fare) {
            $route->fares()->create([
                'tenant_id' => $route->tenant_id,
                'from_sequence' => $fare['from_sequence'] ?? null,
                'to_sequence' => $fare['to_sequence'] ?? null,
                'passenger_type' => $fare['passenger_type'] ?? 'adult',
                'amount' => $fare['amount'],
            ]);
        }
    }
}
