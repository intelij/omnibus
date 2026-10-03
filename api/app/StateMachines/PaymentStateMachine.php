<?php

namespace App\StateMachines;

use App\Exceptions\InvalidStateTransition;
use App\Models\Payment;

class PaymentStateMachine
{
    private const TRANSITIONS = [
        Payment::STATUS_PENDING => [Payment::STATUS_PROCESSING, Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED],
        Payment::STATUS_PROCESSING => [Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED],
        Payment::STATUS_PAID => [Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED],
        Payment::STATUS_PARTIALLY_REFUNDED => [Payment::STATUS_REFUNDED],
        Payment::STATUS_FAILED => [],
        Payment::STATUS_CANCELLED => [],
        Payment::STATUS_EXPIRED => [],
        Payment::STATUS_REFUNDED => [],
    ];

    public function canTransition(Payment $payment, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$payment->status] ?? [], true);
    }

    public function transition(Payment $payment, string $to): Payment
    {
        if (! $this->canTransition($payment, $to)) {
            throw new InvalidStateTransition($payment->status, $to, 'payment');
        }

        $payment->status = $to;
        $payment->save();

        return $payment;
    }
}
