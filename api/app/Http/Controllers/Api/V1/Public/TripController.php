<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\SeatInventoryService;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function show(Trip $trip)
    {
        abort_unless($trip->isBookable(), 404);

        return new TripResource($trip->load(['route.stops', 'bus']));
    }

    public function seats(Trip $trip, Request $request, SeatInventoryService $seats)
    {
        abort_unless($trip->isBookable(), 404);

        $data = $request->validate([
            'boarding' => ['required', 'integer', 'min:0'],
            'dropoff' => ['required', 'integer', 'gt:boarding'],
        ]);

        return response()->json([
            'trip_id' => $trip->id,
            'hold_minutes' => tenant()->bookingRule('seat_hold_minutes'),
            'seats' => $seats->seatMap($trip, (int) $data['boarding'], (int) $data['dropoff']),
        ]);
    }
}
