<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public booking or agent-authed via route middleware
    }

    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'boarding_stop_id' => ['required', 'integer', 'exists:route_stops,id'],
            'dropoff_stop_id' => ['required', 'integer', 'exists:route_stops,id'],
            'boarding_sequence' => ['required', 'integer', 'min:0'],
            'dropoff_sequence' => ['required', 'integer', 'min:0', 'gt:boarding_sequence'],
            'ticket_type' => ['nullable', 'in:dated,open'],
            'payment_method' => ['nullable', 'in:cash,card,ecocash,inbucks'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
            'seat_preference' => ['nullable', 'in:together,front,rear,window,aisle,any'],
            'trip_seat_ids' => ['nullable', 'array'],
            'trip_seat_ids.*' => ['integer', 'distinct', 'exists:trip_seats,id'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'passengers' => ['required', 'array', 'min:1', 'max:'.$this->maxPassengers()],
            'passengers.*.first_name' => ['required', 'string', 'max:80'],
            'passengers.*.last_name' => ['required', 'string', 'max:80'],
            'passengers.*.email' => ['nullable', 'email', 'max:190'],
            'passengers.*.phone' => ['nullable', 'string', 'max:30'],
            'passengers.*.id_document' => ['nullable', 'string', 'max:60'],
            'passengers.*.date_of_birth' => ['nullable', 'date'],
            'passengers.*.gender' => ['nullable', 'in:male,female,other'],
            'passengers.*.passenger_type' => ['nullable', 'in:adult,child,senior'],
            'passengers.*.emergency_contact' => ['nullable', 'string', 'max:60'],
            'passengers.*.special_requirements' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** Tenant-configurable cap — never hard-coded to 10. */
    private function maxPassengers(): int
    {
        $tenant = $this->attributes->get('tenant') ?? tenant();

        return (int) ($tenant?->bookingRule('max_seats_per_booking', 10) ?? 10);
    }
}
