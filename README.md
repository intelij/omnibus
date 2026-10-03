# TransitHub — Multi-tenant Bus Booking & Transport Management

A commercial-grade, multi-tenant booking platform for bus and coach operators — the
bus-industry equivalent of an airline reservation system.

- **API**: Laravel 13 · PHP 8.3+ · Sanctum · Spatie Permission · PostgreSQL (SQLite for dev/test) · Redis queue
- **Web**: Next.js 16 · React 19 · TypeScript · Tailwind · TanStack Query

## What's inside

| Area | Highlights |
|---|---|
| Multi-tenancy | Shared schema + global tenant scope, host/slug resolution, per-tenant RBAC via spatie teams, tenant-aware cache/files/audit |
| Seat engine | Data-driven layouts (designer grid), per-route-leg inventory, timed holds, atomic lock-then-check booking, seat reuse across segments, auto-allocation |
| Booking | Reference-coded bookings, passengers, boarding/drop-off, promotions, idempotent checkout, full event timeline |
| Payments | Cash (manual confirm), Paynow card, EcoCash, InBucks — verified webhooks, dedup by provider event, refunds, reconciliation |
| Tickets | Per-passenger QR tickets, verify + board-scan endpoint |
| Ops | Trips/schedules, bus swaps with re-seating, manifests, support tickets, reports, audit trail, onboarding checklist, feature flags |
| Platform | Tenant onboarding/suspension, cross-tenant aggregates (platform admin only) |

## Quick start

```bash
# Database — MySQL 8+ (Docker):  docker compose up -d mysql
# or point .env at any running MySQL instance (see docs/MYSQL_SETUP.md)

# API (PHP 8.3+, Composer)
cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed   # or: php artisan transithub:seed-demo
php artisan transithub:health      # sanity-check everything
php artisan serve --port=8891

# Web (Node 22+)
cd ../web
npm install
cp .env.example .env.local   # point NEXT_PUBLIC_API_URL at the API
npm run dev                  # http://localhost:3000 (set -p 3100 to match docs)
```

## Seeded accounts

| Account | Email | Role |
|---|---|---|
| Platform admin | `admin@transithub.test` | platform_super_admin |
| Zimbabwe Express admin | `admin@zimbabweexpress.test` | tenant_admin |
| Bulawayo Star admin | `admin@bulawayostar.test` | tenant_admin |

Each tenant also seeds `owner@`, `agent@`, `finance@`, `support@` on the same
domain pattern. Password for **all** seeded users: `password` (development
only — see docs/DEMO_DATA.md).

For local public-site requests pass `?tenant=zimbabwe-express` or the
`X-Tenant` header (dev fallback — production resolves tenants by host/subdomain).

## Useful commands

```bash
# API
php artisan test                 # full suite (sqlite :memory:; run on mysql via docs/MYSQL_SETUP.md)
./vendor/bin/pint                # format
./vendor/bin/phpstan analyse     # static analysis (level 4)
php artisan schedule:work        # hold/payment expiry, trip generation, status transitions
php artisan queue:work

# Web
npm run build && npm run lint
```

## Layout

```
api/            Laravel API
  app/{Models,Services,Payments,Policies,StateMachines,Jobs,Notifications,Support}
  routes/api.php
  database/{migrations,seeders}
  tests/{Unit,Feature}
web/            Next.js app
  src/app       public booking flow + /admin (staff) + /platform
docs/           architecture, security, API, ops & audit documents
```

## Docs

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — system design
- [docs/SEAT_ENGINE.md](docs/SEAT_ENGINE.md) — inventory, holds, locking
- [docs/MULTI_TENANCY.md](docs/MULTI_TENANCY.md) — isolation model
- [docs/PAYMENTS.md](docs/PAYMENTS.md) — gateway contracts & webhooks
- [docs/SECURITY.md](docs/SECURITY.md) — threat model & controls
- [docs/OPERATIONS.md](docs/OPERATIONS.md) — runbook
- [docs/openapi.yaml](docs/openapi.yaml) — API contract
- [docs/MYSQL_SETUP.md](docs/MYSQL_SETUP.md) — database setup & Docker
- [docs/DEMO_DATA.md](docs/DEMO_DATA.md) — seeded dataset & credentials
- [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md) — login flow & roles

## Production notes

- `DB_CONNECTION=mysql` is the default; set `CACHE_STORE`/`QUEUE_CONNECTION` to `redis` in production.
- Fill `PAYNOW_*`, `ECOCASH_*`, `INBUCKS_*` env vars — gateways self-disable when unconfigured.
- Point web `NEXT_PUBLIC_API_URL` at the API host; configure `APP_DOMAIN` for
  tenant subdomain resolution.
- See `docs/DEPLOYMENT.md` and `docs/PRODUCTION_READINESS_REPORT.md` for the full checklist.
