<?php

namespace App\StateMachines;

use App\Exceptions\InvalidStateTransition;
use App\Models\Booking;

class BookingStateMachine
{
    private const TRANSITIONS = [
        Booking::STATUS_DRAFT => [Booking::STATUS_HELD, Booking::STATUS_CANCELLED],
        Booking::STATUS_HELD => [Booking::STATUS_PENDING_PAYMENT, Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED],
        Booking::STATUS_PENDING_PAYMENT => [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED],
        Booking::STATUS_CONFIRMED => [Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED],
        Booking::STATUS_COMPLETED => [Booking::STATUS_REFUNDED],
        Booking::STATUS_CANCELLED => [Booking::STATUS_REFUNDED],
        Booking::STATUS_EXPIRED => [],
        Booking::STATUS_REFUNDED => [],
    ];

    public function canTransition(Booking $booking, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$booking->status] ?? [], true);
    }

    public function transition(Booking $booking, string $to, ?int $actorId = null, ?string $actorType = null, array $metadata = []): Booking
    {
        if (! $this->canTransition($booking, $to)) {
            throw new InvalidStateTransition($booking->status, $to, 'booking');
        }

        $from = $booking->status;
        $booking->status = $to;
        match ($to) {
            Booking::STATUS_CONFIRMED => $booking->confirmed_at = now(),
            Booking::STATUS_CANCELLED => $booking->cancelled_at = now(),
            Booking::STATUS_EXPIRED => $booking->cancelled_at = $booking->cancelled_at ?? now(),
            default => null,
        };
        $booking->save();

        $booking->logEvent("status.{$to}", $actorId, $actorType, array_merge(['from' => $from], $metadata));

        return $booking;
    }
}
