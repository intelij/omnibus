<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /** QR verify — optionally mark boarded. */
    public function verify(Request $request, TicketService $tickets)
    {
        $this->authorize('verify', \App\Models\Ticket::class);

        $data = $request->validate([
            'qr' => ['required', 'string'],
            'mark_used' => ['nullable', 'boolean'],
        ]);

        $result = $tickets->verify($data['qr'], (bool) ($data['mark_used'] ?? false), $request->user());

        return response()->json([
            'valid' => $result['valid'],
            'already_used' => $result['already_used'],
            'ticket' => new TicketResource($result['ticket']),
            'booking' => [
                'reference' => $result['booking']->booking_reference,
                'status' => $result['booking']->status,
                'trip' => $result['booking']->trip?->route?->name,
                'departure_at' => $result['booking']->trip?->departure_at,
                'payment_status' => $result['booking']->paidPayment?->status,
            ],
        ]);
    }
}
