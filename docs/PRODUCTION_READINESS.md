# Production Readiness

Status of the platform against the commercial requirements. Items marked **done**
are implemented and covered by tests or smoke checks; items marked **todo** are
known gaps to close before/after go-live.

## Requirements coverage

| Requirement | Status | Where |
|---|---|---|
| Search by origin/destination/date | done | `GET /public/trips/search` + web `/results` |
| Compare trips (sort by price/departure/duration) | done | search `sort` param |
| Boarding/drop-off stop selection | done | search result + checkout |
| Bus info (type, amenities) | done | `TripResource`, results UI |
| Visual seat map | done | `SeatMap` component + `/public/trips/{id}/seats` |
| Manual + auto seat allocation | done | explicit `trip_seat_ids` or `allocate()` |
| Passenger details | done | `BookingInput` schema + checkout form |
| Promotions | done | `PromotionService`, `/public/promotions/validate`, admin CRUD |
| Card + offline payments | done | Paynow card driver, cash confirm, EcoCash, InBucks |
| Booking confirmation | done | payment → booking confirm → tickets + notification |
| Digital tickets + QR | done | `Ticket::qrPayload`, `/tickets/verify`, QR in UI |
| Booking retrieval | done | ref + contact-key verification |
| Notifications | done | mail driver (extensible to SMS/push) |
| Tenant onboarding | done | `POST /platform/tenants` + checklist endpoint |
| Branding | done | tenant `settings.branding`, applied in web shell |
| Fleet + seat-layout config | done | buses CRUD + layout designer |
| Routes/stops/schedules/fares | done | full CRUD + `GenerateTripsForScheduleJob` |
| Bookings/passengers/agents | done | staff booking search + agent-assisted booking |
| Finance | done | payments ledger, reconciliation, refund queue, CSV export |
| Support | done | tickets + notes + assignment |
| Payment-method config | done | per-tenant enabled methods + gateway availability |
| Customizable booking rules | done | `booking_rules` settings (hold time, cutoff, max seats, cancellation tiers) |
| Operational monitoring | done | dashboard widgets + occupancy/revenue/agent/payment reports |

## Invariants verified by tests

- No double-booking: only one of two concurrent attempts succeeds (row locks + lock-then-check).
- Seat reuse across disjoint legs (Harare→Kwekwe / Kwekwe→Bulawayo share a seat).
- Holds expire and release inventory; expired holds are rejected on confirm.
- A stale payment **cannot** confirm an expired booking.
- Idempotency keys prevent duplicate bookings/payments; webhooks dedupe by provider event.
- Bad webhook signatures rejected; mismatched provider refs rejected.
- Tenant isolation: list/detail/public lookups never leak cross-tenant; suspended tenants blocked.
- RBAC: agents can't confirm cash, support can't see payments, tenants can't grant platform roles, disabled users blocked.

## Security checklist

| Control | Status |
|---|---|
| Tenant scope on every tenant-owned model (global scope + `tenant_id` stamps) | done |
| Tenant middleware ordered **before** route-model binding | done |
| `withoutTenantScope()` only used by platform-level code paths | done |
| Password hashing (bcrypt), Sanctum tokens, `last_login_at` | done |
| Role/permission checks via policies + `authorize()` | done |
| Webhook signature verification per gateway | done |
| Idempotency middleware on mutating endpoints | done |
| Append-only audit log (create-only in app layer) | done |
| Ticket QR payloads — random token, not sequential | done |
| Guest booking access needs ref + contact key | done |
| Rate limiting | todo — add `throttle` per-route in production |
| HTTPS enforcement / HSTS | todo — deploy layer |
| Secrets management | todo — env vars; move to vault/KMS in production |

## Data & infra

- Target DB: **PostgreSQL** (SQLite for dev/test only — `lockForUpdate` is a no-op
  on SQLite; concurrency tests prove logic correctness, Postgres adds real locking).
- Queue: `database` in dev → **Redis** in production.
- Scheduler: hold/payment expiry sweeps every minute, trip generation, status transitions.
- Indexes: tenant_id + status + date indexes on hot paths; unique constraints
  (booking reference, trip seat legs, idempotency keys, promotion codes, webhook events).

## Known gaps (pre-launch hardening)

1. **Per-route rate limiting** — esp. `/public/*` and webhooks.
2. **Request logging & metrics** — request IDs, Prometheus/OTel hooks, slow-query alerts.
3. **Queue retry/dead-letter** — failed-job alerts; webhook redelivery jobs.
4. **Outbox for notifications** — ticket/confirmation mails currently fire inline.
5. **DB-level pessimistic lock verification on Postgres** — SQLite locks are no-ops.
6. **Backup/restore runbook** + audit-log retention/export policy.
7. **Pen test** — IDOR sweep across admin routes, tenant-scope fuzz, seat-race load test.
8. **i18n/a11y pass** — WCAG audit on passenger flow; translations for public copy.
9. **Payment provider sandbox verification** — Paynow/EcoCash/InBucks sandbox keys + webhook IP allowlists.
10. **Data privacy** — passenger-data retention policy, PII export/delete endpoints.
