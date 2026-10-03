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
}
