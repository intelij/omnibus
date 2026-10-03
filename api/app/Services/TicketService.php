<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use App\Support\BookingReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketService
{
    /** Issue one ticket per booked seat on confirmation. */
    public function issueFor(Booking $booking): void
    {
        if ($booking->tickets()->exists()) {
            return; // idempotent
        }

        foreach ($booking->seats()->where('status', 'active')->with('passenger')->get() as $seat) {
            Ticket::create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'booking_passenger_id' => $seat->booking_passenger_id,
                'trip_seat_id' => $seat->trip_seat_id,
                'passenger_name' => $seat->passenger?->fullName() ?? $booking->contact_name,
                'seat_label' => $seat->seat_label,
                'qr_token' => BookingReference::qrToken(),
                'status' => Ticket::STATUS_VALID,
                'issued_at' => now(),
            ]);
        }

        $booking->logEvent('tickets_issued', metadata: ['count' => $booking->tickets()->count()]);
    }

    /**
     * Verify a scanned QR payload. Returns ticket + booking state;
     * `mark_used` turns a valid ticket into used (boarding scan).
     */
    public function verify(string $encoded, bool $markUsed, ?User $actor): array
    {
        $decoded = Ticket::decodeQrPayload($encoded);
        if (! $decoded) {
            throw ValidationException::withMessages(['qr' => 'Invalid ticket code.']);
        }

        $ticket = Ticket::with(['booking.trip.route', 'booking.paidPayment'])
            ->findOrFail($decoded['id']);

        if ($ticket->qr_token !== $decoded['token']) {
            throw ValidationException::withMessages(['qr' => 'Invalid ticket code.']);
        }

        $alreadyUsed = $ticket->status === Ticket::STATUS_USED;

        if ($markUsed && $ticket->status === Ticket::STATUS_VALID) {
            DB::transaction(function () use ($ticket, $actor) {
                $ticket->update([
                    'status' => Ticket::STATUS_USED,
                    'used_at' => now(),
                    'used_by' => $actor?->id,
                ]);
                $ticket->booking->logEvent('ticket_used', $actor?->id, 'user', ['ticket' => $ticket->id]);
            });
        }

        Audit::record('ticket.verified', $ticket, ['mark_used' => $markUsed, 'result' => $ticket->status]);

        return [
            'ticket' => $ticket->fresh(),
            'booking' => $ticket->booking,
            'valid' => $ticket->status === Ticket::STATUS_VALID,
            'already_used' => $alreadyUsed,
        ];
    }
}
