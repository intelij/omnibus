<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->whenLoaded('booking', fn () => $this->booking->booking_reference),
            'method' => $this->method,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'provider_ref' => $this->provider_ref,
            'instructions' => $this->instructions,
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by' => $this->confirmed_by,
            'created_at' => $this->created_at,
            'refunded_amount' => $this->whenLoaded('refunds', fn () => $this->refundedAmount()),
        ];
    }
}
