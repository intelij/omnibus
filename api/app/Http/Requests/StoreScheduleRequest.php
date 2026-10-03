<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_id' => ['required', 'integer', Rule::exists('routes', 'id')->where('tenant_id', tenant_id())],
            'bus_id' => ['required', 'integer', Rule::exists('buses', 'id')->where('tenant_id', tenant_id())],
            'name' => ['nullable', 'string', 'max:120'],
            'departure_time' => ['required', 'date_format:H:i'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'fare_override' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,paused'],
            'generate_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
