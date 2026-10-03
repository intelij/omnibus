# Architecture Decision Records

## ADR-001 — Shared-database multi-tenancy (not stancl/tenancy)

**Decision**: One database, shared schema; every tenant-owned table carries
`tenant_id`, enforced by middleware + global scope + policies + composite
unique keys.

**Alternatives**: stancl/tenancy DB-per-tenant; schema-per-tenant.

**Why**: Hundreds of small operators do not justify per-tenant DBs (ops,
migrations, connection sprawl). Row-level tenancy with disciplined
enforcement layers gives isolation at far lower cost and keeps reporting,
backups and migrations simple. stancl/tenancy was present-but-dead in the
reference app; we implement tenant context explicitly so it is visible and
testable rather than magic.

## ADR-002 — REST API + Next.js (not Inertia/Blade)

**Why**: A Flutter mobile app is a stated future requirement; a versioned
JSON API is mandatory. Next.js App Router gives SSR for SEO-critical
passenger pages and a real component model for the admin console.

## ADR-003 — spatie/laravel-permission with teams = tenant

**Why**: Mature RBAC, granular permission strings, caching. Enabling the
`teams` feature lets the same role names exist per tenant with different
permission sets, and keeps a global `platform_super_admin` outside tenant
scope.

## ADR-004 — Per-trip seat inventory rows (`trip_seats`)

**Decision**: Materialise one `trip_seats` row per (trip, layout seat) at
trip publish time; booking transitions those rows `available → held →
booked` under row locks.

**Alternatives**: derive availability by scanning `booking_seats` (no rows
to lock — race-prone); lock the schedule row only (serialises ALL bookings,
fails segment reuse).

**Why**: Real rows give us something atomic to lock/update, a natural place
for `held_until`, and a clean unique `(trip_id, seat_id)` key. Segment
availability is computed from `booking_seats.boarding_stop_index /
dropoff_stop_index` overlap on top.

## ADR-005 — Explicit state machines

Booking: `draft → held → pending_payment → confirmed → completed` with
`cancelled | expired | refunded` terminals. Payment: `pending → processing
→ paid` with `failed | cancelled | expired | refunded | partially_refunded`.
Ticket: `valid → used` with `cancelled | expired | refunded`.
Transitions live in dedicated classes; no implicit status derivation.

## ADR-006 — Payment gateway contract, thin drivers

`PaymentGateway` interface: `initiate()`, `verify()/webhook()`, `refund()`,
`status()`. Drivers: `card` (provider-agnostic adapter; Paynow-style for
the ZW market), `ecocash`, `inbucks`, `cash` (manual confirmation
workflow). No provider SDK coupling inside booking code; provider-specific
code is isolated per driver and fakeable in tests.

## ADR-007 — Idempotency middleware

`Idempotency-Key` header on mutating booking/payment/refund endpoints.
Keys persist with request fingerprint + stored response; replays return the
stored response; different body + same key → 409. Webhook dedup via
unique `payment_transactions.provider_event_id`.

## ADR-008 — Decimal money, UTC timestamps, tenant timezone display

`decimal(12,2)` + ISO currency on all money columns (portable across
SQLite/Postgres). All timestamps UTC; display in `tenants.timezone`.
Never trust client totals — `PricingService` recomputes server-side.

## ADR-009 — Append-only audit log

`audit_logs` written via an `Audit` service from domain events; no
application update path; records actor, tenant, action, resource, IP,
UA, metadata.

## ADR-010 — Feature flags + subscription plans

`tenant_features` overrides over plan defaults (`starter|professional|
enterprise`). Flags gate *features*; policies still gate *authorisation*.

## ADR-011 — QR tickets as signed opaque tokens

`tickets.qr_token` (random, unique) embedded in QR payload with an HMAC
signature over `ticket_id|token|tenant_id` so a scan resolves
server-side to current status — never trusting the payload alone.
