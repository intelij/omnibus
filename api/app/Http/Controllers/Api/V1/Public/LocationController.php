<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Searchable location feed for the From/To comboboxes.
     * Server-side filtering keeps the DOM small as the dataset grows.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $locations = Location::active()
            ->when($data['q'] ?? null, fn ($q, $term) => $q->search($term))
            ->orderBy('name')
            ->limit(min((int) ($data['limit'] ?? 20), 50))
            ->get(['id', 'name', 'type', 'province']);

        return response()->json(['data' => $locations]);
    }
}
