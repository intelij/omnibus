# Architecture

## Overview

TransitHub is a **modular monolith** multi-tenant SaaS:

```
┌──────────────┐   ┌──────────────┐   (future) Flutter apps
│  web/        │   │  web/        │   passenger / driver / agent
│  Next.js 16  │   │  (admin)     │
│  passenger   │   │              │
└──────┬───────┘   └──────┬───────┘            │
       │                  │                    │
       └──────────────────┼────────────────────┘
                          │ REST  /api/v1  (Sanctum tokens / session)
                ┌─────────▼─────────┐
                │  api/  Laravel 13 │
                │  PHP 8.3          │
                ├───────────────────┤
                │ HTTP  (thin ctrl) │
                │ Domain services   │
                │ Policies / RBAC   │
                │ Tenant scoping    │
                │ Jobs / Events     │
                └───────┬───────────┘
        ┌───────────────┼────────────────┐
   PostgreSQL         Redis          Storage (S3/local)
   (SQLite in dev)  cache+queue      tenant-prefixed keys
```

A single deployable API serves the passenger portal, the operator
back-office, and future mobile clients. The codebase is organised by
**domain** (`app/Domain/{Booking,Fleet,Scheduling,Pricing,Payments,
Promotions,Tenancy,Ticketing,Notifications,Support,Reporting,Identity}`),
each containing its models, services, events and policies. This keeps
business logic out of controllers and allows future extraction into
services without redesign (§102 — no premature microservices).

## Runtime components

| Component | Tech | Purpose |
|---|---|---|
| API | Laravel 13, PHP 8.3 | REST `/api/v1`, Sanctum auth |
| Frontend | Next.js 16 (App Router), React 19, TS, Tailwind 4 | Passenger portal + operator/admin console |
| DB | PostgreSQL (prod), SQLite (dev/test) | Shared schema, `tenant_id` columns |
| Cache/Queue | Redis (prod), `database`/`array` (dev/test) | Holds expiry, notifications, reports |
| Files | `local`/`s3` disk, `tenants/{id}/…` prefixes | Logos, ticket assets, exports |

## Request lifecycle (authenticated tenant request)

1. `auth:sanctum` resolves the user.
2. `tenant` middleware resolves the tenant (user's `tenant_id`, or host
   subdomain for public booking endpoints) and binds it in the container
   + sets spatie permission "team" context.
3. Route-model binding + `BelongsToTenant` global scope constrain every
   query to the tenant.
4. Policies check permission strings (`bookings.cancel`, …).
5. Controller delegates to a domain service inside `DB::transaction`.
6. Domain events dispatch queued listeners (notifications, audit,
   ticket generation).

## Layers

- **HTTP**: FormRequests (validation), Controllers (thin), API Resources
  (consistent output), middleware (auth, tenant, idempotency, throttle).
- **Domain**: Services (BookingService, SeatInventoryService,
  PricingService, PaymentService, PromotionService…), state machines,
  domain events, policies.
- **Infrastructure**: gateway drivers (payments), notification channels,
  storage, audit writer, feature flags.

## Key flows

- Booking: search → trip → hold seats → passenger details → price →
  promo → payment intent → confirm → ticket. See BOOKING_ENGINE.md.
- Seat inventory: per-trip rows, held/booked states, segment-aware
  overlap. See SEAT_ENGINE.md.
- Payments: provider drivers behind a contract; webhooks verified,
  idempotent, audited. See PAYMENTS.md.

## Scaling posture

Designed for Postgres+Redis production, horizontal API replicas, and a
queue worker fleet. Indexes cover search/manifest/report queries. No
Elasticsearch, no Kubernetes, no event streaming — added only when real
load requires it.
