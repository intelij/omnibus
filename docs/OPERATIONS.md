# Operations

## Scheduled jobs (Laravel scheduler)

| Job | Frequency | Idempotent? |
|---|---|---|
| ReleaseExpiredSeatHoldsJob | every minute | yes — only rows `held_until < now()` |
| ExpirePendingBookingsJob | every minute | yes — pending_payment older than TTL |
| ReconcilePaymentsJob | every 5 min | yes — polls open provider refs |
| TripStatusTransitionJob | every 5 min | yes — time-based state checks |
| UpcomingTripNotificationJob | hourly | yes — `notification_sent_at` marker |
| GenerateDailyReportsJob | nightly | yes — upserts by (tenant, date, type) |
| CleanupExpiredDataJob | nightly | yes — idempotent deletes |

All jobs extend `TenantAwareJob` (re-binds tenant) and are safe to re-run.

## Queues

`high` (payments, ticket issue) · `default` (notifications) · `low`
(reports, exports). Retries with backoff; failures land in `failed_jobs`;
`queue:failed`/`retry` documented below.

## Routine ops

- **Failed queue jobs**: `php artisan queue:failed`; inspect;
  `queue:retry {id}` after fixing cause; `queue:flush` only when certain.
- **Stuck payment**: admin Payments → transaction history shows provider
  events; `ReconcilePaymentsJob` self-heals most; manual confirm only via
  `payments.reconcile` UI with reason → audited.
- **Trip cancellation**: `POST /trips/{id}/status {cancelled}` → auto
  passenger notifications + refund eligibility flagging.
- **Bus swap**: `POST /trips/{id}/swap-bus` → seats re-allocated,
  passengers notified.
- **Suspicious webhook**: audit logs + `payment_transactions.payload_hash`;
  invalid signatures already rejected.
- **Restore**: PITR DB restore to point-in-time → storage restore →
  redeploy same tag. Drill quarterly.

## Runbook signals

Alert on: failed_jobs growth, payment failure spike, hold-release rate
anomaly, queue latency > 60s, `/health/ready` non-200, 5xx rate.
