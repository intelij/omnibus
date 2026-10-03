<?php

namespace App\Payments\Drivers;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * InBucks mobile-money driver — same contract as EcoCash; provider-specific
 * auth/payload isolated here so booking code never knows the difference.
 */
class InbucksGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'inbucks';
    }

    public function label(): string
    {
        return 'InBucks';
    }

    public function isAvailable(): bool
    {
        $cfg = config('transithub.payment_gateways.inbucks');

        return ! empty($cfg['api_key']) && ! empty($cfg['endpoint']);
    }

    public function initiate(Payment $payment): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('inbucks');
        }

        $cfg = config('transithub.payment_gateways.inbucks');

        $response = Http::withToken($cfg['api_key'])->post($cfg['endpoint'] . '/payments', [
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->booking->booking_reference,
            'phone' => $payment->booking->contact_phone,
            'callback_url' => route('webhooks.payments', 'inbucks'),
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return ['type' => 'none', 'message' => 'InBucks request failed. Try again or choose another method.'];
        }

        $payment->update(['provider_ref' => $body['id'] ?? null]);

        return [
            'type' => 'ussd',
            'message' => 'Approve the InBucks prompt on your phone to complete payment.',
            'provider_ref' => $body['id'] ?? null,
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $secret = config('transithub.payment_gateways.inbucks.webhook_secret');
        $signature = (string) $request->header('X-Inbucks-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), (string) $secret);

        if ($secret && ! hash_equals($expected, $signature)) {
            abort(401, 'Invalid signature.');
        }

        $data = $request->json()->all();

        return [
            'event_id' => 'inbucks:' . ($data['id'] ?? '') . ':' . ($data['status'] ?? ''),
            'status' => $this->normalizeStatus((string) ($data['status'] ?? '')),
            'provider_ref' => $data['id'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'booking_reference' => $data['reference'] ?? null,
            'payload' => $data,
        ];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('inbucks');
        }

        $cfg = config('transithub.payment_gateways.inbucks');
        $response = Http::withToken($cfg['api_key'])->post($cfg['endpoint'] . '/refunds', [
            'payment_id' => $payment->provider_ref,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        return [
            'provider_ref' => $response->json('id'),
            'status' => $response->successful() ? 'processed' : 'failed',
        ];
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'success', 'successful', 'paid', 'completed' => Payment::STATUS_PAID,
            'failed' => Payment::STATUS_FAILED,
            'cancelled' => Payment::STATUS_CANCELLED,
            'expired' => Payment::STATUS_EXPIRED,
            'processing', 'initiated', 'pending' => Payment::STATUS_PROCESSING,
            default => Payment::STATUS_PENDING,
        };
    }
}
