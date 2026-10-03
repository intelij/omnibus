<?php

namespace App\Services;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentDiscrepancy;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\PollableGateway;
use App\StateMachines\BookingStateMachine;
use App\StateMachines\PaymentStateMachine;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private GatewayManager $gateways,
        private PaymentStateMachine $states,
        private BookingStateMachine $bookingStates,
        private BookingService $bookings,
    ) {}

    /**
     * Create or reuse the open payment for a booking and start the provider
     * flow. Idempotent: an already-open payment is returned as-is (double
     * Pay click safe).
     */
    public function initiate(Booking $booking, string $method, ?string $idempotencyKey = null): Payment
    {
        $gateway = $this->gateways->driver($method);

        if (! $gateway->isAvailable()) {
            throw new GatewayUnavailableException($method);
        }

        /** @var Payment $payment */
        $payment = DB::transaction(function () use ($booking, $method, $gateway, $idempotencyKey) {
            $booking->refresh();

            if (! in_array($booking->status, [Booking::STATUS_HELD, Booking::STATUS_PENDING_PAYMENT], true)) {
                throw ValidationException::withMessages(['booking' => 'This booking is not awaiting payment.']);
            }

            $existing = $booking->openPayment;
            if ($existing && $existing->method === $method) {
                return $existing;
            }

            // A different method means the customer abandoned the old attempt
            // — never leave two payable payments open on one booking.
            if ($existing && $existing->method !== $method) {
                $this->states->transition($existing, Payment::STATUS_CANCELLED);
            }

            if ($booking->status === Booking::STATUS_HELD) {
                $this->bookingStates->transition($booking, Booking::STATUS_PENDING_PAYMENT);
                // Booking expiry now tracks the payment window, not the seat hold.
                $booking->update(['expires_at' => now()->addMinutes((int) $booking->tenant->bookingRule('pending_payment_minutes', 30))]);
            }

            $payment = Payment::create([
                'booking_id' => $booking->id,
                'method' => $method,
                'provider' => $method,
                'amount' => $booking->total,
                'currency' => $booking->currency,
                'status' => Payment::STATUS_PENDING,
                'expires_at' => now()->addMinutes((int) $booking->tenant->bookingRule('pending_payment_minutes', 30)),
                'idempotency_key' => $idempotencyKey,
            ]);

            $initiation = $gateway->initiate($payment);

            $payment->update(['instructions' => $initiation['message'] ?? null,
                'provider_ref' => $initiation['provider_ref'] ?? $payment->provider_ref,
                'metadata' => array_merge($payment->metadata ?? [], ['initiation' => $initiation])]);

            PaymentTransaction::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'type' => PaymentTransaction::TYPE_INITIATE,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'payload' => ['method' => $method],
                'result' => $initiation,
            ]);

            if (($initiation['type'] ?? null) === 'ussd' || ($initiation['type'] ?? null) === 'redirect') {
                $this->states->transition($payment, Payment::STATUS_PROCESSING);
            }

            Audit::record('payment.initiated', $payment, ['method' => $method]);

            return $payment->fresh();
        });

        return $payment;
    }

    /**
     * Process a verified provider webhook. Idempotent via provider_event_id
     * unique constraint — duplicate deliveries are acknowledged no-ops.
     */
    public function handleWebhook(string $gatewayKey, Request $request): PaymentTransaction
    {
        $gateway = $this->gateways->driver($gatewayKey);
        $event = $gateway->parseWebhook($request); // throws 401 on bad signature

        $reference = $event['booking_reference'] ?? null;
        $payment = Payment::withoutTenantScope()
            ->where('provider_ref', $event['provider_ref'] ?? '∅')
            ->when(! $event['provider_ref'], fn ($q) => $q->whereRaw('1=0'))
            ->first()
            ?? ($reference ? Payment::withoutTenantScope()
                ->whereHas('booking', fn ($q) => $q->where('booking_reference', $reference))
                ->latest()->first() : null);

        if (! $payment) {
            return new PaymentTransaction(['type' => PaymentTransaction::TYPE_CALLBACK, 'result' => ['unmatched' => true]]);
        }

        $existing = PaymentTransaction::withoutTenantScope()
            ->where('payment_id', $payment->id)
            ->where('provider_event_id', $event['event_id'])
            ->first();
        if ($existing) {
            return $existing; // replay → no-op
        }

        return DB::transaction(function () use ($payment, $event) {
            $tx = PaymentTransaction::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'type' => PaymentTransaction::TYPE_CALLBACK,
                'provider_event_id' => $event['event_id'],
                'status' => $event['status'],
                'amount' => $event['amount'] ?? null,
                'payload' => $event['payload'] ?? null,
                'payload_hash' => hash('sha256', json_encode($event['payload'] ?? [])),
            ]);

            // Amount verification — a "paid" callback for the wrong amount
            // must never confirm a booking. Flag it for finance review.
            if ($event['status'] === Payment::STATUS_PAID
                && isset($event['amount'])
                && abs($event['amount'] - (float) $payment->amount) > 0.005) {
                $this->flagDiscrepancy($payment, PaymentDiscrepancy::TYPE_AMOUNT_MISMATCH, [
                    'event_id' => $event['event_id'],
                ], receivedAmount: $event['amount']);

                return $tx;
            }

            $this->applyProviderStatus($payment, $event['status'], $event['provider_ref'] ?? null);

            Audit::record('payment.webhook', $payment, [
                'event' => $event['event_id'], 'status' => $event['status'],
            ], tenantId: $payment->tenant_id);

            return $tx;
        });
    }

    public function applyProviderStatus(Payment $payment, string $status, ?string $providerRef = null): Payment
    {
        $payment->refresh();
        if ($providerRef) {
            $payment->provider_ref = $providerRef;
        }

        match ($status) {
            Payment::STATUS_PAID => $this->markPaid($payment),
            Payment::STATUS_FAILED => $this->states->canTransition($payment, Payment::STATUS_FAILED)
                && $this->states->transition($payment, Payment::STATUS_FAILED),
            Payment::STATUS_CANCELLED => $this->states->canTransition($payment, Payment::STATUS_CANCELLED)
                && $this->states->transition($payment, Payment::STATUS_CANCELLED),
            Payment::STATUS_EXPIRED => $this->states->canTransition($payment, Payment::STATUS_EXPIRED)
                && $this->states->transition($payment, Payment::STATUS_EXPIRED),
            Payment::STATUS_PROCESSING => $this->states->canTransition($payment, Payment::STATUS_PROCESSING)
                && $this->states->transition($payment, Payment::STATUS_PROCESSING),
            default => null,
        };

        return $payment->fresh();
    }

    /** Staff cash confirmation — caller must hold payments.confirm_cash. */
    public function confirmCash(Payment $payment, User $actor): Payment
    {
        if ($payment->method !== 'cash') {
            throw ValidationException::withMessages(['payment' => 'Only cash payments are confirmed manually.']);
        }

        return DB::transaction(function () use ($payment, $actor) {
            $this->states->transition($payment, Payment::STATUS_PAID);
            $payment->update(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);

            PaymentTransaction::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'type' => PaymentTransaction::TYPE_CONFIRMATION,
                'status' => Payment::STATUS_PAID,
                'amount' => $payment->amount,
                'result' => ['confirmed_by' => $actor->id],
            ]);

            $this->bookings->confirm($payment->booking, $actor);
            Audit::record('payment.cash_confirmed', $payment, [], $actor->id);

            return $payment->fresh();
        });
    }

    private function markPaid(Payment $payment): void
    {
        if (! $this->states->canTransition($payment, Payment::STATUS_PAID)) {
            return; // already terminal / idempotent
        }
        $this->states->transition($payment, Payment::STATUS_PAID);
        $payment->confirmed_at = now();
        $payment->save();

        $booking = $payment->booking->fresh();
        if ($this->bookingStates->canTransition($booking, Booking::STATUS_CONFIRMED)) {
            $this->bookings->confirm($booking);
        } else {
            // Money arrived but the booking can no longer be confirmed
            // (expired/cancelled) — flag for finance; never resurrect the
            // booking or steal seats back from whoever holds them now.
            $this->flagDiscrepancy($payment, PaymentDiscrepancy::TYPE_LATE_PAYMENT, [
                'booking_status' => $booking->status,
            ]);
        }
    }

    /**
     * Raise a finance-review flag. Stored, audited, and notified so nothing
     * is silently dropped — a real payment discrepancy needs a human refund
     * or correction, not a swallowed exception.
     */
    public function flagDiscrepancy(
        Payment $payment,
        string $type,
        array $context = [],
        ?float $receivedAmount = null,
    ): PaymentDiscrepancy {
        $discrepancy = PaymentDiscrepancy::create([
            'tenant_id' => $payment->tenant_id,
            'payment_id' => $payment->id,
            'type' => $type,
            'expected_amount' => $payment->amount,
            'received_amount' => $receivedAmount,
            'status' => 'open',
            'context' => $context,
        ]);

        Audit::record('payment.discrepancy', $payment, [
            'discrepancy_id' => $discrepancy->id, 'type' => $type,
        ], tenantId: $payment->tenant_id);

        return $discrepancy;
    }

    /**
     * Poll pending/processing payments against providers that support
     * status queries (Paynow pollurl). Catches missed webhooks.
     */
    public function reconcilePending(int $limit = 50): int
    {
        $pending = Payment::withoutTenantScope()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->whereNotNull('provider_ref')
            ->where('created_at', '<', now()->subMinutes(2))
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subDay());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        foreach ($pending as $payment) {
            $gateway = $this->gateways->driver($payment->method);
            if (! $gateway instanceof PollableGateway) {
                continue;
            }

            $result = $gateway->statusCheck($payment);
            if (! $result || ! $result['status']) {
                $payment->forceFill([
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'poll_count' => (int) ($payment->metadata['poll_count'] ?? 0) + 1,
                    ]),
                ])->save();

                continue;
            }
            $checked++;

            DB::transaction(function () use ($payment, $result) {
                PaymentTransaction::create([
                    'tenant_id' => $payment->tenant_id,
                    'payment_id' => $payment->id,
                    'type' => PaymentTransaction::TYPE_STATUS_POLL,
                    'status' => $result['status'],
                    'amount' => $result['amount'] ?? null,
                    'payload' => $result['payload'] ?? null,
                ]);

                if ($result['status'] === Payment::STATUS_PAID
                    && isset($result['amount'])
                    && abs($result['amount'] - (float) $payment->amount) > 0.005) {
                    $this->flagDiscrepancy($payment, PaymentDiscrepancy::TYPE_AMOUNT_MISMATCH, [
                        'source' => 'status_poll',
                    ], receivedAmount: $result['amount']);

                    return;
                }

                $this->applyProviderStatus($payment, $result['status'], $result['provider_ref'] ?? null);
            });
        }

        return $checked;
    }

    /**
     * Refund a paid payment (full or partial). Cash refunds stay pending
     * for finance reconciliation; provider refunds call the driver.
     */
    public function refund(Payment $payment, float $amount, string $reason, ?User $actor): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor) {
            $payment->refresh();

            if ($payment->status !== Payment::STATUS_PAID && $payment->status !== Payment::STATUS_PARTIALLY_REFUNDED) {
                throw ValidationException::withMessages(['payment' => 'Only paid payments can be refunded.']);
            }

            if ($amount <= 0 || $amount > $payment->refundableAmount()) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the refundable amount.']);
            }

            $gateway = $this->gateways->driver($payment->method);
            $result = $gateway->refund($payment, $amount, $reason);

            $refund = Refund::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => $result['status'] === 'processed' ? Refund::STATUS_PROCESSED : Refund::STATUS_PENDING,
                'requested_by' => $actor?->id,
                'provider_ref' => $result['provider_ref'] ?? null,
                'processed_by' => $result['status'] === 'processed' ? $actor?->id : null,
                'processed_at' => $result['status'] === 'processed' ? now() : null,
            ]);

            if ($refund->status === Refund::STATUS_PROCESSED) {
                $this->syncRefundState($payment);
            }

            PaymentTransaction::create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'type' => PaymentTransaction::TYPE_REFUND,
                'status' => $refund->status,
                'amount' => $amount,
                'result' => $result,
            ]);

            Audit::record('payment.refund_requested', $payment, [
                'refund_id' => $refund->id, 'amount' => $amount, 'reason' => $reason,
            ], $actor?->id);

            return $refund;
        });
    }

    public function markRefundProcessed(Refund $refund, ?User $actor = null): Refund
    {
        $refund->update([
            'status' => Refund::STATUS_PROCESSED,
            'processed_by' => $actor?->id,
            'processed_at' => now(),
        ]);
        $this->syncRefundState($refund->payment);

        return $refund;
    }

    private function syncRefundState(Payment $payment): void
    {
        $payment->refresh();
        $refunded = $payment->refundedAmount();
        $to = $refunded >= (float) $payment->amount
            ? Payment::STATUS_REFUNDED
            : Payment::STATUS_PARTIALLY_REFUNDED;
        if ($this->states->canTransition($payment, $to) || $payment->status === $to) {
            $payment->status = $to;
            $payment->save();
        }

        if ($to === Payment::STATUS_REFUNDED) {
            $booking = $payment->booking;
            if ($booking->status !== Booking::STATUS_REFUNDED
                && $this->bookingStates->canTransition($booking, Booking::STATUS_REFUNDED)) {
                $this->bookingStates->transition($booking, Booking::STATUS_REFUNDED);
            }
        }
    }

    /** Expire open payments past TTL; expiry cascades to the booking. */
    public function expireStale(): int
    {
        $stale = Payment::withoutTenantScope()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($stale as $payment) {
            DB::transaction(function () use ($payment) {
                $payment->status = Payment::STATUS_EXPIRED;
                $payment->save();

                $booking = $payment->booking;
                if ($booking && in_array($booking->status, [Booking::STATUS_PENDING_PAYMENT, Booking::STATUS_HELD], true)) {
                    $this->bookings->expire($booking);
                }
            });
        }

        return $stale->count();
    }
}
