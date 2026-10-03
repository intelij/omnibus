<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Contract every payment method implements. Drivers isolate provider
 * specifics; booking code only ever talks to PaymentService.
 */
interface PaymentGateway
{
    public function key(): string;

    /** Human label for UIs, e.g. "EcoCash". */
    public function label(): string;

    /** False when the provider isn't configured — never fake success. */
    public function isAvailable(): bool;

    /**
     * Start the payment. Returns instructions for the client:
     * ['type' => 'redirect'|'ussd'|'cash'|'none', 'url'?, 'message'?]
     */
    public function initiate(Payment $payment): array;

    /**
     * Verify and decode an inbound provider webhook.
     * @return array{event_id:string, status:string, provider_ref?:string, amount?:float, payload:array}
     */
    public function parseWebhook(Request $request): array;

    /**
     * Execute a refund with the provider.
     * @return array{provider_ref: string|null, status: string}
     */
    public function refund(Payment $payment, float $amount, string $reason): array;

    /** Map provider-specific status to our canonical Payment statuses. */
    public function normalizeStatus(string $providerStatus): string;
}
