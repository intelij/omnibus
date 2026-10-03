<?php

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Trip */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'departure_at' => $this->departure_at,
            'arrival_estimate_at' => $this->arrival_estimate_at,
            'base_fare' => (float) $this->base_fare,
            'currency' => $this->tenant?->currency,
            'route' => $this->whenLoaded('route', fn () => [
                'id' => $this->route->id,
                'name' => $this->route->name,
                'origin' => $this->route->origin,
                'destination' => $this->route->destination,
                'duration_minutes' => $this->route->duration_minutes,
                'stops' => $this->route->relationLoaded('stops') ? $this->route->stops->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'sequence' => $s->sequence,
                    'can_board' => $s->can_board, 'can_alight' => $s->can_alight,
                    'offset_minutes' => $s->offset_minutes,
                ]) : null,
            ]),
            'bus' => $this->whenLoaded('bus', fn () => $this->bus ? [
                'id' => $this->bus->id,
                'registration' => $this->bus->registration_number,
                'type' => $this->bus->bus_type,
                'amenities' => $this->bus->amenities,
                'decks' => $this->bus->decks,
            ] : null),
            'driver_name' => $this->driver_name,
        ];
    }
}
