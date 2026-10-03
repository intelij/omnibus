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
| Rate limiting — named per-endpoint limiters (see routes/api.php + AppServiceProvider) | done |
| HTTPS enforcement / HSTS (`FORCE_HTTPS`/`FORCE_HSTS` env-gated) | done — deploy-layer TLS still required |
| Secrets management — env vars, never logged/committed (Mask); vault migration documented | done (env) |

## Data & infra

- Target DB: **PostgreSQL** (SQLite for dev/test only — `lockForUpdate` is a no-op
  on SQLite; concurrency tests prove logic correctness, Postgres adds real locking).
- Queue: `database` in dev → **Redis** in production.
- Scheduler: hold/payment expiry sweeps every minute, trip generation, status transitions.
- Indexes: tenant_id + status + date indexes on hot paths; unique constraints
  (booking reference, trip seat legs, idempotency keys, promotion codes, webhook events).

## Hardening release (2026-10-03) — status of former gaps

1. ~~Per-route rate limiting~~ — **done** (named limiters, live-verified 429s).
2. ~~Request logging~~ — **done** (X-Request-ID + shared log context + audit request_id).
   Metrics/OTel backend wiring remains a deploy-time hook.
3. ~~Queue retry/dead-letter~~ — **done** (tries/backoff/failed() audit on all jobs).
4. ~~Outbox~~ — **done** (`QUEUE_AFTER_COMMIT` + queued notifications; domain events persist in-tx).
5. ~~Postgres lock verification~~ — **done** (suite green on PG 15; 8-way seat race = 1 winner).
6. ~~Backup/restore~~ — **done** (`docs/BACKUP_AND_RESTORE.md` incl. audit retention).
7. **Pen test** — **partial**: security test matrix documented (`docs/SECURITY_TEST_MATRIX.md`),
   automated IDOR/RBAC/webhook/race coverage; external scanner + Paynow IP allowlist remain.
8. **i18n/a11y** — **partial**: WCAG fixes done (contrast, focus, seat states, skip links,
   labels); i18n architecture ready (`tenant.locale`) but copy not yet extracted.
9. **Payment sandbox verification** — **open**: Paynow wiring complete + hash-verified;
   one live sandbox round-trip still required before launch.
10. ~~Data privacy~~ — **done** (retention sweep, PII export + anonymize endpoints, log masking).

See `docs/PRODUCTION_READINESS_REPORT.md` for the full audit.

## Zimbabwe rollout additions (2026-10-03, second pass)

- Canonical `locations` dataset (33 ZW cities/towns, slug-unique, extensible
  via admin) + `GET /public/locations?q=` server-side search.
- From/To → accessible combobox (keyboard nav, clear, same-city prevention);
  same origin/destination rejected client + server side.
- Passenger count → numeric stepper bound to tenant `max_seats_per_booking`.
- `SeatLayoutGenerator` — config-driven ZW layouts (2+2 + rear 5 = 49,
  2+1, 1+1, 3+2), stable `{row}{col-letter}` labels, draft-only regen.
- Admin: "ZW coach config" generator panel + agent multi-passenger booking
  (`/admin/bookings/new`) with live seat map.
- Trip snapshots unchanged — published layouts and `trip_seats` keep
  existing bookings stable across bus re-config.
- New tests: `tests/Feature/ZwBookingFlowTest.php` (10 tests, 33 assertions).
