<?php

namespace App\Payments\Drivers;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Card payments via a hosted-redirect provider (Paynow-compatible shape).
 * We never touch PAN/CVV — the provider hosts the card capture; we store
 * only the provider reference, status, amount and reconciliation metadata.
 *
 * Requires config: CARD_PROVIDER credentials (transithub.payment_gateways.card).
 * Without credentials the driver reports unavailable and initiate() throws
 * — real integration stays config-gated, nothing is faked.
 */
class CardGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Card (Visa/Mastercard)';
    }

    public function isAvailable(): bool
    {
        $cfg = config('transithub.payment_gateways.card');

        return ! empty($cfg['merchant_id']) && ! empty($cfg['merchant_key']);
    }

    public function initiate(Payment $payment): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('card');
        }

        $cfg = config('transithub.payment_gateways.card');
        $booking = $payment->booking;

        $fields = [
            'id' => $cfg['merchant_id'],
            'reference' => $booking->booking_reference,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'additionalinfo' => "Bus ticket {$booking->booking_reference}",
            'returnurl' => $cfg['return_url'] ?? url("/booking/{$booking->booking_reference}"),
            'resulturl' => route('webhooks.payments', 'card'),
            'authemail' => $booking->contact_email ?? '',
            'status' => 'Message',
        ];
        $fields['hash'] = $this->hash($fields, $cfg['merchant_key']);

        $response = Http::asForm()->post('https://www.paynow.co.zw/interface/initiatetransaction', $fields);

        if (! $response->successful() || ! str_contains($response->body(), 'browserurl=')) {
            return [
                'type' => 'none',
                'message' => 'Card checkout could not be started. Try another method or contact support.',
            ];
        }

        parse_str($response->body(), $parsed);

        $payment->update(['provider_ref' => $parsed['pollurl'] ?? null]);

        return [
            'type' => 'redirect',
            'url' => urldecode($parsed['browserurl'] ?? ''),
            'provider_ref' => $parsed['pollurl'] ?? null,
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $cfg = config('transithub.payment_gateways.card');
        $data = $request->all();
        $expected = $this->hash($data, $cfg['merchant_key'] ?? '');

        if (! hash_equals($expected, (string) ($data['hash'] ?? ''))) {
            abort(401, 'Invalid signature.');
        }

        return [
            'event_id' => 'card:'.($data['reference'] ?? '').':'.($data['paynowreference'] ?? ''),
            'status' => $this->normalizeStatus((string) ($data['status'] ?? '')),
            'provider_ref' => $data['paynowreference'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'booking_reference' => $data['reference'] ?? null,
            'payload' => $data,
        ];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        // Hosted-redirect providers typically settle refunds via their
        // merchant console; we record intent + provider ref when available.
        return ['provider_ref' => $payment->provider_ref, 'status' => 'pending'];
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'paid', 'awaiting delivery', 'delivered' => Payment::STATUS_PAID,
            'cancelled', 'disputed' => Payment::STATUS_CANCELLED,
            'failed' => Payment::STATUS_FAILED,
            'created', 'sent' => Payment::STATUS_PROCESSING,
            default => Payment::STATUS_PENDING,
        };
    }

    private function hash(array $fields, string $key): string
    {
        $concat = '';
        foreach ($fields as $k => $v) {
            if (strtolower($k) === 'hash' || $v === '') {
                continue;
            }
            $concat .= $v;
        }

        return strtoupper(hash('sha512', $concat.$key));
    }
}
