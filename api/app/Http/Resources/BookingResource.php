<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->booking_reference,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount_amount,
            'total' => (float) $this->total,
            'ticket_type' => $this->ticket_type,
            'contact' => ['name' => $this->contact_name, 'email' => $this->contact_email, 'phone' => $this->contact_phone],
            'boarding_sequence' => $this->boarding_sequence,
            'dropoff_sequence' => $this->dropoff_sequence,
            'boarding' => $this->whenLoaded('boardingStop', fn () => ['id' => $this->boardingStop->id, 'name' => $this->boardingStop->name]),
            'dropoff' => $this->whenLoaded('dropoffStop', fn () => ['id' => $this->dropoffStop->id, 'name' => $this->dropoffStop->name]),
            'trip' => $this->whenLoaded('trip', fn () => new TripResource($this->trip)),
            'seats' => $this->whenLoaded('seats', fn () => $this->seats->map(fn ($s) => [
                'label' => $s->seat_label, 'fare' => (float) $s->fare, 'status' => $s->status,
            ])),
            'passengers' => $this->whenLoaded('passengers', fn () => $this->passengers->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'type' => $p->passenger_type,
            ])),
            'promotion_code' => $this->promotion_code,
            'payment' => $this->whenLoaded('latestPayment', fn () => $this->latestPayment ? [
                'id' => $this->latestPayment->id,
                'method' => $this->latestPayment->method,
                'status' => $this->latestPayment->status,
                'instructions' => $this->latestPayment->instructions,
            ] : null),
            'expires_at' => $this->expires_at,
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
