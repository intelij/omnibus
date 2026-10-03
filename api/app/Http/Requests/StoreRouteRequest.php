<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'origin' => ['required', 'string', 'max:120'],
            'destination' => ['required', 'string', 'max:120', 'different:origin'],
            'distance_km' => ['nullable', 'integer', 'min:1'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'base_fare' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            'stops' => ['required', 'array', 'min:2'],
            'stops.*.name' => ['required', 'string', 'max:120'],
            'stops.*.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'stops.*.sequence' => ['required', 'integer', 'min:0', 'distinct'],
            'stops.*.can_board' => ['boolean'],
            'stops.*.can_alight' => ['boolean'],
            'stops.*.offset_minutes' => ['nullable', 'integer', 'min:0'],
            'fares' => ['nullable', 'array'],
            'fares.*.from_sequence' => ['nullable', 'integer', 'min:0'],
            'fares.*.to_sequence' => ['nullable', 'integer', 'min:0'],
            'fares.*.passenger_type' => ['required', 'in:adult,child,senior'],
            'fares.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
