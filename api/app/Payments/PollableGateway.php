<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Implemented by drivers whose provider offers a status query endpoint
 * (e.g. Paynow pollurl). The reconciliation job polls these payments so a
 * missed/delayed webhook doesn't leave bookings stuck pending_payment.
 */
interface PollableGateway extends PaymentGateway
{
    /**
     * Query the provider for the latest status of this payment.
     * Returns ['status' => canonical status, 'provider_ref' => string|null,
     *          'amount' => float|null, 'payload' => array] or null when the
     * payment can't be polled (missing provider ref / provider error).
     */
    public function statusCheck(Payment $payment): ?array;
}
