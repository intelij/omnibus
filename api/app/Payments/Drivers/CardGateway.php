<?php

namespace App\Payments\Drivers;

use App\Exceptions\GatewayUnavailableException;
use App\Models\Payment;
use App\Payments\PollableGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Card payments via Paynow hosted checkout (paynow.co.zw).
 * We never touch PAN/CVV — Paynow hosts the card capture; we store only
 * the provider reference (pollurl/paynowreference), status and amount.
 *
 * Credentials: PAYNOW_INTEGRATION_ID / PAYNOW_INTEGRATION_KEY. Without them
 * the driver reports unavailable — real integration stays config-gated.
 *
 * Paynow lifecycle:
 *   initiate → POST initiatetransaction → browserurl (customer redirect)
 *            → resulturl callback (server-to-server, hash-verified)
 *            → pollurl status check (reconciliation fallback)
 */
class CardGateway implements PollableGateway
{
    public function key(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return 'Card (Paynow)';
    }

    public function isAvailable(): bool
    {
        $cfg = $this->cfg();

        return ! empty($cfg['integration_id']) && ! empty($cfg['integration_key']);
    }

    public function initiate(Payment $payment): array
    {
        if (! $this->isAvailable()) {
            throw new GatewayUnavailableException('card');
        }

        $cfg = $this->cfg();
        $booking = $payment->booking;

        $fields = [
            'id' => $cfg['integration_id'],
            'reference' => $booking->booking_reference,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'additionalinfo' => "Bus ticket {$booking->booking_reference}",
            'returnurl' => $cfg['return_url']
                ?? rtrim((string) config('transithub.frontend_url'), '/')."/booking/{$booking->booking_reference}",
            'resulturl' => $cfg['result_url'] ?? route('webhooks.payments', 'card'),
            'authemail' => $booking->contact_email ?? '',
            'status' => 'Message',
        ];
        $fields['hash'] = $this->hash($fields, $cfg['integration_key']);

        $response = Http::asForm()
            ->timeout(15)
            ->post($cfg['initiate_endpoint'], $fields);

        if (! $response->successful() || ! str_contains($response->body(), 'browserurl=')) {
            return [
                'type' => 'none',
                'message' => 'Card checkout could not be started. Try another method or contact support.',
            ];
        }

        parse_str($response->body(), $parsed);

        // Fail closed if Paynow's own response hash is wrong — a tampered or
        // misconfigured endpoint must never send the customer somewhere bogus.
        if (isset($parsed['hash']) && ! hash_equals(
            $this->hash($parsed, $cfg['integration_key']),
            (string) $parsed['hash']
        )) {
            return [
                'type' => 'none',
                'message' => 'Card checkout could not be started. Try another method or contact support.',
            ];
        }

        $payment->update(['provider_ref' => $parsed['pollurl'] ?? null]);

        return [
            'type' => 'redirect',
            'url' => urldecode($parsed['browserurl'] ?? ''),
            'provider_ref' => $parsed['pollurl'] ?? null,
            'message' => 'You will be redirected to Paynow to complete payment.',
        ];
    }

    /**
     * Paynow result (server-to-server callback). Verified with the shared
     * integration-key hash over the received fields — unverifiable or missing
     * hashes are rejected before any state change.
     */
    public function parseWebhook(Request $request): array
    {
        $cfg = $this->cfg();
        $data = $request->all();
        $expected = $this->hash($data, $cfg['integration_key'] ?? '');

        if (! hash_equals($expected, (string) ($data['hash'] ?? ''))) {
            abort(401, 'Invalid signature.');
        }

        return [
            'event_id' => 'card:'.($data['reference'] ?? '').':'.($data['paynowreference'] ?? ''),
            'status' => $this->normalizeStatus((string) ($data['status'] ?? '')),
            'provider_ref' => $data['paynowreference'] ?? ($data['pollurl'] ?? null),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'booking_reference' => $data['reference'] ?? null,
            'payload' => $data,
        ];
    }

    /**
     * Poll Paynow for the current status of an initiated payment.
     * provider_ref holds the pollurl returned at initiation.
     */
    public function statusCheck(Payment $payment): ?array
    {
        $pollUrl = $payment->metadata['initiation']['poll_url'] ?? $payment->provider_ref;
        if (! $pollUrl || ! str_starts_with((string) $pollUrl, 'http')) {
            return null;
        }

        $response = Http::asForm()->timeout(10)->post($pollUrl);
        if (! $response->successful()) {
            return null;
        }

        parse_str($response->body(), $data);
        $cfg = $this->cfg();

        if (isset($data['hash']) && ! hash_equals(
            $this->hash($data, $cfg['integration_key'] ?? ''),
            (string) $data['hash']
        )) {
            return null; // don't trust unverifiable status payloads
        }

        return [
            'status' => $this->normalizeStatus((string) ($data['status'] ?? '')),
            'provider_ref' => $data['paynowreference'] ?? $pollUrl,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'payload' => $data,
        ];
    }

    public function refund(Payment $payment, float $amount, string $reason): array
    {
        // Paynow card refunds are processed via the merchant console; record
        // intent + provider ref so finance can reconcile.
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

    private function cfg(): array
    {
        return config('transithub.payment_gateways.card', []);
    }

    /**
     * Paynow hash: SHA512 over concatenated field values (excluding the hash
     * field itself and empty values) + integration key, uppercase hex.
     */
    private function hash(array $fields, string $key): string
    {
        $concat = '';
        foreach ($fields as $k => $v) {
            if (strtolower((string) $k) === 'hash' || $v === '' || $v === null) {
                continue;
            }
            $concat .= $v;
        }

        return strtoupper(hash('sha512', $concat.$key));
    }
}
