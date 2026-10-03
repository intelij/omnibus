<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seat_layout_id' => ['nullable', 'integer', Rule::exists('seat_layouts', 'id')->where('tenant_id', tenant_id())],
            // Inline seating configuration — generates a layout atomically.
            // Capacity is always derived server-side from the resulting seats;
            // a client-supplied capacity is never trusted.
            'seating_config' => ['nullable', 'array'],
            'seating_config.name' => ['nullable', 'string', 'max:80'],
            'seating_config.rows' => ['required_with:seating_config', 'integer', 'min:1', 'max:40'],
            'seating_config.left_seats' => ['required_with:seating_config', 'integer', 'min:0', 'max:4'],
            'seating_config.right_seats' => ['required_with:seating_config', 'integer', 'min:0', 'max:4'],
            'seating_config.rear_row_seats' => ['nullable', 'integer', 'min:0', 'max:10'],
            'seating_config.label_style' => ['nullable', 'in:row_letter,numeric'],
            'registration_number' => ['required', 'string', 'max:30',
                Rule::unique('buses')->where('tenant_id', tenant_id())->ignore($this->route('bus'))],
            'fleet_number' => ['nullable', 'string', 'max:30'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 2)],
            'bus_type' => ['required', 'in:coach,minibus,double_decker,vip_coach'],
            'decks' => ['nullable', 'integer', 'in:1,2'],
            'amenities' => ['nullable', 'array'],
            'accessibility' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive,maintenance,retired'],
            'photos' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Creating a bus requires SOMETHING to sit in — an existing layout
            // or a seating configuration to generate one from.
            if ($this->route('bus')) {
                return; // update path — layout may already be bound
            }
            if (! $this->seat_layout_id && ! $this->seating_config) {
                $v->errors()->add('seat_layout_id', 'Please configure the seating layout before saving the bus.');
            }
            $cfg = $this->seating_config;
            if ($cfg && (($cfg['left_seats'] ?? 0) + ($cfg['right_seats'] ?? 0)) === 0) {
                $v->errors()->add('seating_config', 'At least one side of the aisle must have seats.');
            }
        });
    }
}
