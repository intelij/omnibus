# Testing

Stack: PHPUnit 12 (Laravel `php artisan test`), SQLite `testing` DB,
Playwright for E2E. `composer test` / `npm run test:e2e`.

## Pyramid

- **Unit** (`tests/Unit`): PricingService, RefundCalculator,
  AutoAllocator, PromotionService rules, state machines, QR signing,
  booking reference format.
- **Feature** (`tests/Feature`): auth, RBAC matrix, tenant isolation,
  booking flow, seat holds/expiry, payments per driver, webhooks,
  refunds, promotions, tickets/QR, support, reports, notifications.
- **Integration**: gateway drivers against recorded/mock providers;
  queues with `Queue::fake()` + a sync-mode pass.
- **E2E** (`web/e2e`): full passenger booking, agent cash booking,
  finance confirm, support resend — §100 matrix.

## Critical concurrency suite (`tests/Feature/Concurrency`)

| Scenario | Method | Expectation |
|---|---|---|
| 1. Two passengers, same seat | Parallel `POST /bookings` (2 processes / `parallel` test) | exactly one 2xx, loser gets 409 |
| 2. Two agents, same seat | same, agent tokens | one succeeds |
| 3. Pay after hold expiry | hold, `travel(11)->minutes()`, pay | re-takes seat or routes to review — never double-books |
| 4. Duplicate webhook | POST same `provider_event_id` twice | one confirmation, second acked no-op |
| 5. Double-click Pay | two POSTs same idempotency key | one payment, identical response |

Concurrency tests run against the real DB (SQLite `database` connection
with `PRAGMA busy_timeout` + transactional locking semantics — the
SQLite-serialised writer still exercises the lock+check protocol; CI runs
the same suite on Postgres).

## Tenant isolation suite

For every tenant-owned resource: Tenant A user GET/PUT/DELETE of Tenant B
id → 404; list endpoints contain zero foreign rows; idempotency keys are
tenant-namespaced; storage paths differ. `TenantScopeArchitectureTest`
asserts every model in the tenant list uses `BelongsToTenant`.

## Security suite

IDOR, auth-required matrix per route, throttle hit → 429, webhook bad
signature → 401, upload type/size rejection, XSS payload stored escaped.

## E2E matrix coverage

§67 list implemented as Playwright specs grouped: `booking.spec.ts`
(one-way/return/multi-pax/manual+auto seats/promo paths),
`payment.spec.ts` (success/fail/pending/cash/refund), `ops.spec.ts`
(agent, finance, support flows), `edge.spec.ts` (full trip, expired hold,
cancelled booking, QR reuse).

## Coverage & gates

- Unit+feature coverage target ≥80% on `app/Domain`, enforced in CI.
- CI fails the build on any critical-suite or isolation failure.
- `php artisan test --parallel` default; `--group=concurrency,security`
  selectable.
