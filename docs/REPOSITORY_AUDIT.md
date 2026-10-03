# Reference Repository Audit

Audit performed 2026-10-03 against the two provided reference repositories.

## 1. TransitHub (`~/Code/transithub`)

Laravel 13 / PHP 8.3 Blade application (~1.5k LOC of app code). Session auth,
server-rendered Blade UI, `stancl/tenancy` and `spatie/laravel-permission`
present in `composer.json` but **not wired up** (no `tenancy.php`, no tenancy
middleware, roles unused).

### What is reusable (concepts, not code)

- Transactional booking create with `lockForUpdate()` on the schedule row —
  the correct instinct for concurrency; kept and extended to row-level seat
  locking.
- `booking_seats` join table with `unique(schedule_id, seat_number)` —
  the right *idea* (DB-level double-book protection), redesigned into
  per-trip seat inventory.
- Human-readable booking references (`TH-XXXXXXXX`).
- `dompdf` ticket PDFs + `simple-qrcode` QR codes.
- `SpecialOffer` flat/percent discount with per-route scoping — the seed of
  the promotions engine.

### What is broken / unsafe / must not be copied

- **CSV seat storage**: `bookings.seat_number` stores `"1,2,3"` and a
  `unique(schedule_id, seat_number)` index treats the CSV as one value, so
  `"1,2"` and `"2,3"` do not conflict. Double-booking is possible across
  overlapping subsets.
- **No tenant isolation**: `company_id` exists but nothing scopes queries;
  admin routes are guarded by bare `auth` middleware — any logged-in user can
  administer every company.
- **No seat holds**: seats go straight from available to booked; two users in
  checkout race on `available_seats` counter alone.
- **No payment state machine**: `PaymentGatewayService` is a `match` that
  mostly creates "manual" payments; no webhooks, no idempotency, admins
  `markPaid` with no permission check or audit trail.
- **Procedural seat layouts**: `Bus::seatDecks()` generates rows from
  `capacity` + a hardcoded layout enum; no per-seat types, no persistence,
  not editable by operators.
- **No multi-passenger bookings** (one `passenger_name` per booking), no
  booking/cancellation/refund states beyond `pending|paid`, no audit log, no
  notifications, no API surface, no meaningful tests (one feature test).
- Route model has no stops/boarding points; schedule = route+bus+date+time
  with no trip/seat-instance separation.

## 2. Cemetery Management (`~/Code/cemetry`)

Laravel 13 + Inertia + `nwidart/laravel-modules`. **Largely a skeleton**: 8
modules each containing providers/routes/config scaffolding and Inertia
placeholders; ~80 PHP files, no domain models, no RBAC implementation, no
tests. The substantial content is the specification documents
(`SYSTEM_SPECIFICATION.md`, `BURIAL_REGISTRY_ERP_PROPOSAL.md`).

### Reusable patterns

- **Modular monolith organisation** — domain modules with their own routes,
  providers, views. We adopt the *spirit* (domain-oriented directory
  structure inside `app/Domain/*`) without taking on `nwidart` machinery.
- Spec-document rigor: explicit sections for audit, security, deployment,
  API, testing — mirrored in this repo's `docs/` set.
- Inertia `Layouts`/`Pages` structure informs the Next.js route-group layout
  split `(public)` / `admin`.

### Not applicable

- All cemetery domain concepts (plots, deeds, GIS/PostGIS) are excluded.
- Inertia is rejected in favour of a real API + Next.js (mobile-readiness
  requirement — an Inertia app cannot serve a Flutter client).

## 3. Design consequences

| Reference finding | Decision in bus-service |
|---|---|
| CSV seat bug | `trip_seats` row per seat per trip; atomic state transitions |
| No tenant isolation | `tenant_id` on every tenant table + global scope + middleware + policies + composite unique keys |
| No holds | `held` state with `held_until`, lazy + scheduled release |
| Manual payments | `PaymentGateway` contract; webhook verification; idempotency keys; finance-only cash confirmation |
| Procedural layouts | `seat_layouts`/`seat_layout_seats` grid model + visual designer |
| Blade-only | REST API (`/api/v1`) + Next.js passenger/admin frontend; mobile-ready |
| Route = schedule | `routes`/`route_stops`/`schedules`/`trips` modelled independently; segment-aware seat occupancy |
