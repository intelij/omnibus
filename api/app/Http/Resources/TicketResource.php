<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'passenger_name' => $this->passenger_name,
            'seat_label' => $this->seat_label,
            'status' => $this->status,
            'qr' => $this->qrPayload(),
            'issued_at' => $this->issued_at,
            'used_at' => $this->used_at,
        ];
    }
}
