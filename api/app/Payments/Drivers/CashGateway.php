<?php

namespace App\Payments\Drivers;

use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Cash: no provider calls. Creates a pending payment with collection
 * instructions; a `payments.confirm_cash`-permissioned user confirms it.
 */
class CashGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'cash';
    }

    public function label(): string
    {
        return 'Cash';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function initiate(Payment $payment): array
    {
        return [
            'type' => 'cash',
            'message' => "Collect {$payment->currency} {$payment->amount} for booking {$payment->booking->booking_reference}. A finance user must confirm receipt.",
        ];
    }

    public function parseWebhook(Request $request): array
    {
        throw new \BadMethodCallException('Cash payments do not have webhooks.');
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        // Cash refunds are recorded as pending and reconciled by finance.
        return ['provider_ref' => null, 'status' => 'pending'];
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'paid', 'confirmed' => Payment::STATUS_PAID,
            'cancelled' => Payment::STATUS_CANCELLED,
            default => Payment::STATUS_PENDING,
        };
    }
}
