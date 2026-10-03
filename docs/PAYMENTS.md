# Payments

## Abstraction

```php
interface PaymentGateway {
    public function key(): string;                    // 'card'|'ecocash'|'inbucks'|'cash'
    public function initiate(Payment $payment): PaymentInitiation;  // instructions / redirect
    public function refund(Payment $payment, int $amountMinor, string $reason): RefundResult;
    public function verifyWebhook(Request $request): WebhookEvent;    // signature-checked
    public function status(Payment $payment): string;
}
```

`PaymentService` (the engine) owns orchestration: creating payments,
state transitions, webhook routing, refunds, reconciliation.
`PaymentGatewayManager` resolves drivers by key. Booking code speaks only
to `PaymentService` — §87.

## Drivers

| Driver | Behaviour |
|---|---|
| `cash` | Creates `pending` payment + instructions; `payments.confirm_cash`-permissioned staff confirm → `paid` with `confirmed_by/at`, audit-logged. |
| `card` | PCI-safe redirect/token flow via provider adapter. **No PAN/CVV storage** — only `provider_ref`, status, amount, metadata. Config-gated; without credentials the driver is `unavailable` (not faked). |
| `ecocash` | Initiate → customer USSD push; statuses `initiated/pending/successful/failed/cancelled/expired`; signed webhook callback. |
| `inbucks` | Same contract; own credential/config mapping. |

Adding Stripe/Yoco/PayPal = new driver class + config, no booking changes.

## Payment states

`pending → processing → paid` · terminals: `failed`, `cancelled`,
`expired`, `refunded`, `partially_refunded`. Independent of booking state
(§32). `PaymentStateMachine` guards transitions; `payments` + child
`payment_transactions` (every provider event: initiate, callback, refund)
form an append-only financial ledger — history is never edited (§88).

## Webhooks

`POST /api/v1/webhooks/payments/{gateway}`:

1. Signature verification (HMAC/shared-secret per driver) → 401 on failure.
2. `provider_event_id` dedup — unique index on `payment_transactions`;
   replayed deliveries are acknowledged but processed once (Scenario 4).
3. Async handling (`ProcessPaymentWebhook` job) → state transition →
   booking confirmation + ticket issue + notifications.
4. Everything audit-logged with payload hash.

## Idempotency

- API: `Idempotency-Key` on POST /payments (and /bookings, /refunds).
- Provider: unique event ids.
- Retry-safe `initiate`: returns the existing open payment for a booking
  instead of creating a duplicate (Scenario 5).

## Reconciliation

`payments` vs `payment_transactions` vs provider statements;
`payments.reconcile` UI lists pending/failed/late-confirmed items;
nightly `ReconcilePaymentsJob` polls open payments and expires stale ones.
Exports are permission-gated CSV/JSON.
