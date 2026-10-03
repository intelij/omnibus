<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function handle(string $gateway, Request $request, PaymentService $payments)
    {
        // Signature verification inside the driver — bad sig → 401 before
        // any state change. Duplicate provider_event_id → stored no-op.
        $tx = $payments->handleWebhook($gateway, $request);

        return response()->json(['received' => true, 'id' => $tx->id ?? null]);
    }
}
