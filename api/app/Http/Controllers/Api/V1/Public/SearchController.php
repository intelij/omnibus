<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Services\TripSearchService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SearchController extends Controller
{
    public function __construct(private TripSearchService $search) {}

    public function endpoints()
    {
        return response()->json($this->search->endpoints());
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:120'],
            'to' => ['required', 'string', 'max:120', 'different:from'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sort' => ['nullable', 'in:price,-price,departure,-departure,duration'],
        ]);

        if (mb_strtolower(trim($data['from'])) === mb_strtolower(trim($data['to']))) {
            throw ValidationException::withMessages([
                'to' => 'Origin and destination must be different cities.',
            ]);
        }

        $results = $this->search->search(
            $data['from'], $data['to'], $data['date'], (int) ($data['passengers'] ?? 1),
        );

        $results = match ($data['sort'] ?? 'departure') {
            'price' => $results->sortBy('fare')->values(),
            '-price' => $results->sortByDesc('fare')->values(),
            '-departure' => $results->sortByDesc(fn ($r) => $r['trip']->departure_at)->values(),
            'duration' => $results->sortBy(fn ($r) => $r['trip']->route->duration_minutes ?? 0)->values(),
            default => $results->sortBy(fn ($r) => $r['trip']->departure_at)->values(),
        };

        return response()->json([
            'data' => $results->map(fn ($r) => [
                'trip' => new TripResource($r['trip']),
                'boarding_stop' => ['id' => $r['boarding_stop']->id, 'name' => $r['boarding_stop']->name, 'sequence' => $r['boarding_stop']->sequence],
                'dropoff_stop' => ['id' => $r['dropoff_stop']->id, 'name' => $r['dropoff_stop']->name, 'sequence' => $r['dropoff_stop']->sequence],
                'available_seats' => $r['available_seats'],
                'fare' => $r['fare'],
                'bookable' => $r['bookable'],
            ]),
        ]);
    }
}
