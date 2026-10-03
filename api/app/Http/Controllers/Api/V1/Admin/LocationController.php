<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Staff view of the location dataset — operators add towns they serve that
 * aren't in the canonical ZW seed yet. Dataset is shared geography (not
 * tenant data), so additions benefit every tenant.
 */
class LocationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('routes.view'), 403);

        return response()->json([
            'data' => Location::active()->orderBy('name')->get(['id', 'name', 'type', 'province', 'country_code']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('routes.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'in:city,town'],
            'province' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $location = Location::updateOrCreate(
            ['slug' => Str::slug($data['name']), 'country_code' => 'ZW'],
            $data + ['is_active' => true],
        );

        return response()->json(['data' => $location], 201);
    }

    /** Edit fields or activate/deactivate a location (deactivated = not bookable). */
    public function update(Request $request, Location $location)
    {
        abort_unless($request->user()->can('routes.create'), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:city,town'],
            'province' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if (isset($data['name']) && $data['name'] !== $location->name) {
            $data['slug'] = Str::slug($data['name']);
        }

        $location->update(array_filter($data, fn ($v) => ! is_null($v)));

        return response()->json(['data' => $location->fresh()]);
    }
}
