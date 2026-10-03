<?php

namespace App\Payments\Drivers;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * EcoCash (Cassava) via merchant API. Customer authorises on their handset;
 * the provider calls our signed webhook with the final status.
 * Credentials gate availability — nothing simulated.
 */
class EcocashGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'ecocash';
    }

    public function label(): string
    {
        return 'EcoCash';
    }

    public function isAvailable(): bool
    {
        $cfg = config('transithub.payment_gateways.ecocash');

        return ! empty($cfg['merchant_code']) && ! empty($cfg['merchant_pin']) && ! empty($cfg['endpoint']);
    }

    public function initiate(Payment $payment): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('ecocash');
        }

        $cfg = config('transithub.payment_gateways.ecocash');

        $response = Http::post($cfg['endpoint'] . '/v1/transaction/initiate', [
            'merchantCode' => $cfg['merchant_code'],
            'merchantPin' => $cfg['merchant_pin'],
            'merchantNumber' => $cfg['merchant_number'] ?? null,
            'currency' => $payment->currency,
            'amount' => (float) $payment->amount,
            'reference' => $payment->booking->booking_reference,
            'phone' => $payment->booking->contact_phone,
            'callbackUrl' => route('webhooks.payments', 'ecocash'),
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return ['type' => 'none', 'message' => 'EcoCash request failed. Try again or choose another method.'];
        }

        $payment->update(['provider_ref' => $body['transactionReference'] ?? null]);

        return [
            'type' => 'ussd',
            'message' => 'Check your phone and approve the EcoCash prompt to complete payment.',
            'provider_ref' => $body['transactionReference'] ?? null,
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $secret = config('transithub.payment_gateways.ecocash.webhook_secret');
        $signature = (string) $request->header('X-Ecocash-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), (string) $secret);

        if ($secret && ! hash_equals($expected, $signature)) {
            abort(401, 'Invalid signature.');
        }

        $data = $request->json()->all();

        return [
            'event_id' => 'ecocash:' . ($data['transactionReference'] ?? '') . ':' . ($data['status'] ?? ''),
            'status' => $this->normalizeStatus((string) ($data['status'] ?? '')),
            'provider_ref' => $data['transactionReference'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'booking_reference' => $data['reference'] ?? null,
            'payload' => $data,
        ];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('ecocash');
        }

        $cfg = config('transithub.payment_gateways.ecocash');
        $response = Http::post($cfg['endpoint'] . '/v1/transaction/refund', [
            'merchantCode' => $cfg['merchant_code'],
            'merchantPin' => $cfg['merchant_pin'],
            'transactionReference' => $payment->provider_ref,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        return [
            'provider_ref' => $response->json('refundReference'),
            'status' => $response->successful() ? 'processed' : 'failed',
        ];
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'success', 'successful', 'completed' => Payment::STATUS_PAID,
            'failed' => Payment::STATUS_FAILED,
            'cancelled' => Payment::STATUS_CANCELLED,
            'expired' => Payment::STATUS_EXPIRED,
            'initiated', 'pending' => Payment::STATUS_PROCESSING,
            default => Payment::STATUS_PENDING,
        };
    }
}
