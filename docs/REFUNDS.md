# Refunds & Cancellations

## Cancellation policy (tenant-configured, not hard-coded)

`tenant_booking_rules.cancellation_policy` — ordered windows:

```json
[{"min_hours_before":48,"percent":100},
 {"min_hours_before":24,"percent":50},
 {"min_hours_before":0,"percent":0}]
```

`RefundCalculator::for(booking, now)` picks the first matching window.
`bookings.cancellation_allowed` is false inside cutoff or after departure.

## Flow

1. Actor with `bookings.cancel` (or passenger self-service within policy)
   cancels → state machine validates `confirmed|pending_payment → cancelled`.
2. Seats released (`booked → available`) inside the same transaction.
3. If a `paid` payment exists and refund amount > 0 →
   `PaymentService::refund(payment, amount, reason, actor)`:
   - requires `bookings.refund`/`payments.refund` for staff-initiated,
   - gateway `refund()` driver call,
   - `refunds` row: amount, reason, `requested_by`, `approved_by`, provider
     ref, status `pending → processed|failed`,
   - payment transitions `refunded` / `partially_refunded`,
   - booking → `refunded` when fully refunded.
4. Audit events + notifications (`booking.cancelled`, `refund.processed`).

## Invariants

- Refund total ≤ payment total (enforced by check + service).
- Cash refunds never auto-execute — recorded `pending`, finance reconciles.
- Partial refunds supported (one passenger of many).
- Historical payment rows are never mutated; reversals are new ledger rows.
