<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Bus;
use App\Models\Trip;
use App\Models\TripSeatLeg;
use App\Services\SeatInventoryService;
use App\Services\TripService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function __construct(private TripService $trips, private SeatInventoryService $seats) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Trip::class);

        $trips = Trip::with(['route:id,name,origin,destination', 'bus:id,registration_number'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->route_id, fn ($q, $v) => $q->where('route_id', $v))
            ->when($request->date_from, fn ($q, $v) => $q->where('departure_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('departure_at', '<=', $v))
            ->orderBy('departure_at')
            ->paginate($request->integer('per_page', 20));

        return TripResource::collection($trips);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Trip::class);

        $data = $request->validate([
            'route_id' => ['required', 'integer', Rule::exists('routes', 'id')->where('tenant_id', tenant_id())],
            'bus_id' => ['required', 'integer', Rule::exists('buses', 'id')->where('tenant_id', tenant_id())],
            'schedule_id' => ['nullable', 'integer', Rule::exists('schedules', 'id')->where('tenant_id', tenant_id())],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'departure_at' => ['required', 'date', 'after:now'],
            'base_fare' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trip = $this->trips->create($data);

        return (new TripResource($trip->load(['route.stops', 'bus'])))->response()->setStatusCode(201);
    }

    public function show(Trip $trip)
    {
        $this->authorize('view', $trip);

        $trip->load(['route.stops', 'bus.seatLayout']);

        return (new TripResource($trip))->additional([
            'booked_seats' => TripSeatLeg::where('trip_id', $trip->id)->where('state', 'booked')->distinct('trip_seat_id')->count('trip_seat_id'),
        ]);
    }

    public function seats(Trip $trip, Request $request)
    {
        $this->authorize('view', $trip);

        $data = $request->validate([
            'boarding' => ['nullable', 'integer', 'min:0'],
            'dropoff' => ['nullable', 'integer'],
        ]);

        $first = $trip->route->stops()->min('sequence') ?? 0;
        $last = $trip->route->stops()->max('sequence') ?? 1;

        return response()->json([
            'trip_id' => $trip->id,
            'seats' => $this->seats->seatMap($trip, (int) ($data['boarding'] ?? $first), (int) ($data['dropoff'] ?? $last)),
        ]);
    }

    public function status(Trip $trip, Request $request)
    {
        $this->authorize('update', $trip);

        $data = $request->validate([
            'status' => ['required', 'in:boarding,departed,in_transit,arrived,delayed,cancelled,completed'],
            'reason' => ['nullable', 'string', 'max:500'],
            'delayed_until' => ['nullable', 'date'],
        ]);

        $trip = $this->trips->transitionStatus($trip, $data['status'], $data, $request->user());

        return new TripResource($trip);
    }

    public function swapBus(Trip $trip, Request $request)
    {
        $this->authorize('update', $trip);

        $data = $request->validate([
            'bus_id' => ['required', 'integer', Rule::exists('buses', 'id')->where('tenant_id', tenant_id())],
        ]);

        $bus = Bus::findOrFail($data['bus_id']);
        if (! $bus->seatLayout) {
            return response()->json(['message' => 'Replacement bus has no seat layout configured.'], 422);
        }

        return new TripResource($this->trips->swapBus($trip, $bus)->load(['route.stops', 'bus']));
    }

    public function manifest(Trip $trip)
    {
        $this->authorize('manifest', $trip);

        $rows = $trip->bookings()
            ->whereIn('status', ['confirmed', 'pending_payment'])
            ->with(['passengers', 'seats', 'boardingStop', 'dropoffStop', 'latestPayment'])
            ->get()
            ->flatMap(fn ($b) => $b->passengers->map(fn ($p) => [
                'reference' => $b->booking_reference,
                'passenger' => $p->fullName(),
                'seat' => $b->seats->firstWhere('booking_passenger_id', $p->id)?->seat_label,
                'boarding' => $b->boardingStop?->name,
                'dropoff' => $b->dropoffStop?->name,
                'payment' => $b->latestPayment?->status,
                'booking_status' => $b->status,
                'special_requirements' => $p->special_requirements,
            ]));

        return response()->json(['trip_id' => $trip->id, 'departure_at' => $trip->departure_at, 'manifest' => $rows->values()]);
    }
}
