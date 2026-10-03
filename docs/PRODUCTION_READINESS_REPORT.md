# Production Readiness Report — TransitHub

**Date:** 2026-10-03 · **Scope:** API (Laravel 13) + Web (Next.js 16) hardening release

## Executive summary

The platform is in a **deployable, commercially-ready state** for a controlled
launch behind TLS with managed Postgres + Redis. The hardening pass closed the
critical gaps: a systemic white-on-white UI defect, real Paynow credential
wiring with verified callbacks and reconciliation, payment amount verification,
late-payment handling, named rate limiting, security headers, request
correlation, PII rights, and **PostgreSQL-verified seat locking**.

**Quality gates:** 39 tests / 138 assertions green on **both** SQLite and
PostgreSQL 15 · PHPStan level 4 clean · Pint clean · `next build` clean ·
ESLint clean · 8-way parallel seat race on Postgres: **exactly one winner**.

## UI/UX

**Root-cause defect fixed:** the scaffold's `prefers-color-scheme: dark` media
query flipped `--foreground` to near-white (`#ededed`) on dark-OS machines —
every unstyled-text element rendered white-on-white across cards, tables, and
forms. Removed and replaced with a semantic token layer.

Fixes implemented:

- **Design tokens** (`globals.css`): `--surface`, `--border`, `--ink*`,
  `--brand*`, `--danger`, `--info*`, `--warning*` mapped through `@theme` —
  ~30 files migrated from raw `slate-*`/`teal-*` to semantic utilities
  (`bg-surface`, `text-ink-muted`, `ring-border`, `bg-brand`).
- **Contrast floor:** `text-slate-400` (3.5:1, fails WCAG AA on white) replaced
  app-wide with `text-ink-faint` (#64748b = 4.6:1).
- **Tenant-brand safety** (`lib/brand.ts`): operators can configure any brand
  colour — `readableOnWhite`/`safeBgForWhiteText`/`textOn` compute luminance
  and clamp to ≥4.5:1, applied to header accents, sidebar, and the marketing
  hero gradient so a white/yellow brand pick can never produce white-on-white.
- **Seat map states** no longer colour-only: striped pattern + ✕ for taken,
  dashed border + 🔒 for held, ✓ for selected, rings + icons for
  VIP/premium/accessible; `aria-label`/`aria-selected`/`role=grid` semantics,
  keyboard focusable, forced-colors fallback, legend with non-colour glyphs.
- **A11y pass:** skip-links on public + admin shells, `:focus-visible` brand
  outline, `role="alert"` error banners, `aria-current` pagination,
  `role="dialog"` modals, real `<label>`s (no placeholder-only labels),
  visible `required` attributes on every form control.
- **Loading/empty/error:** `Spinner`, `EmptyState`, `ErrorBanner` primitives
  used consistently; human-readable copy — no raw exception text reaches users.

## Paynow & payments

- **Existing credentials used:** `PAYNOW_INTEGRATION_ID/KEY` (already in env) —
  config realigned to those names; no second payment stack introduced.
- **Initiation** → `initiatetransaction` with SHA512 field-hash; the
  **response hash is verified** before redirecting the customer (a tampered
  endpoint can't fake a browser URL). `pollurl` stored as `provider_ref`.
- **Result callback** → hash-verified; `provider_event_id` dedup makes
  replays no-ops.
- **Amount verification:** a `paid` event whose amount ≠ expected never
  confirms — it creates an **open `payment_discrepancies` record** surfaced
  in the admin Reconciliation panel with a resolve action + audit trail.
- **Late payment** (booking already expired): payment records `paid` (the
  money is real) but the booking is **not** resurrected and no seat is
  stolen back — a `late_payment` discrepancy flags finance for refund.
- **Missed webhooks:** `ReconcilePendingPaymentsJob` every 5 min polls
  `pollurl` via the new `PollableGateway::statusCheck()` contract.
- **Method switching** cancels the prior open payment — one payable payment
  per booking, always.
- **Failure UX:** booking page now offers *Complete payment* (resume
  redirect), *Retry payment*, and *pay another way* while the hold window
  remains — with the visible deadline.

## Security

- Named rate limiters: `auth` 5/min, `password-reset` 5/min, `public-search`
  60/min, `bookings` 20/min, `payment-initiation` 10/min, `promo-validation`
  30/min, `ticket-retrieval` 30/min, `webhook` 120/min — verified live.
- Headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, CSP `default-src 'none'; frame-ancestors 'none'`
  (API), env-gated HSTS.
- `RequestId` middleware: `X-Request-ID` echoed, stashed for audit rows and
  `Log::shareContext` — request→booking→payment→webhook→ticket traceable.
- `FORCE_HTTPS` → `URL::forceHttps()` in production; secure generated URLs.
- Audit trail extended with `request_id`; **PII masking** applied to audit
  metadata (emails/phones masked, secret keys redacted).
- Full matrix with statuses: `docs/SECURITY_TEST_MATRIX.md`.

## Multi-tenancy

No new leakage found in the hardening pass; the earlier defence-in-depth holds:
global scope on every tenant-stamped model, tenant middleware ordered **before**
route-model binding, host/public-tenant resolution, suspended-tenant blocking,
platform team-0 for cross-tenant roles. Eight isolation tests green on both
databases.

## Database — PostgreSQL verification

- All migrations (incl. new indexes/columns) run cleanly on **PostgreSQL 15**.
- Full suite: **39/39 green on pgsql** (`composer test:pg` /
  `scripts/test-pgsql.sh`).
- **Real concurrency:** 8 parallel same-seat requests → 1×201, 7×409;
  `trip_seat_legs` rows show exactly one booking owning all 4 legs.
- 20 parallel distinct-seat bookings → all valid seats booked; partially
  occupied seats correctly rejected at leg level.
- `FOR UPDATE` row locking (seat legs, promotion row, booking) is
  production-meaningful on Postgres; the unique constraints
  (`booking_reference`, `tenant+idempotency_key`,
  `payment+provider_event_id`, `booking+trip_seat`) are the backstop.
- New indexes cover the hot sweeps: `(state, held_until)`,
  `bookings(status, expires_at)`, `+tenant/created_at` reporting paths.

## Queues, scheduler & outbox

- All jobs: `tries=3` + exponential `backoff` + `failed()` audit alerting;
  notifications `tries=3`.
- `QUEUE_AFTER_COMMIT=true` (env) → queued notifications/jobs push only after
  the booking transaction commits — booking stays valid even if mail is down;
  domain events (`booking_events`) + audit rows persist atomically inside the
  tx (transactional-outbox equivalent).
- Scheduler (all `withoutOverlapping`): seat-hold sweep + payment expiry
  (1 min), trip-status transitions (5 min), **payment reconciliation**
  (5 min), trip generation + **PII anonymization** (daily).

## Observability

`X-Request-ID` correlation end-to-end; shared log context (`request_id`,
`tenant_id`); structured JSON errors in prod (`app.debug` guard); slow-path
indexes added; failed jobs persist to `failed_jobs` **and** audit. Metrics
hooks are the documented extension point (`docs/PRODUCTION_READINESS.md`).

## Privacy

- `GET public/bookings/{ref}/privacy-export` — guest PII export (contact-key
  gated, audited).
- `POST public/bookings/{ref}/anonymize` — erasure on settled bookings;
  financial records retained, identifying fields masked.
- Daily retention sweep (`pii_retention_days`, default 730) — including the
  linked passenger-directory rows when they hold no live bookings.
- `Mask` utility: audit/log PII masked; secrets redacted.

## Testing executed

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite) | **39 tests / 138 assertions — pass** |
| `php artisan test` (PostgreSQL 15) | **39/39 — pass** (`scripts/test-pgsql.sh`) |
| PHPStan level 4 | clean |
| `next build` | clean, 23 routes |
| `eslint` | 0 errors |
| Parallel seat race (PG, live server) | 8 req → 1 winner |
| Search burst | 50 parallel → 2.2 s total, limiter-capped correctly |
| E2E journeys | covered at API/feature level (booking, payment, ticket, agent, finance flows); browser E2E not run — no Playwright in env |

## Remaining risks (genuine only)

1. **Paynow sandbox round-trip** — credentials and full flow are wired and
   hash-verified, but a live `initiate→callback→poll` cycle hasn't been run
   against Paynow's sandbox; do one before go-live (env-dependent).
2. **Paynow IP allowlist** — no published ranges verified; signature+dedup is
   the control today.
3. **i18n** — architecture ready (`tenant.locale` field); copy is still
   inline English — extraction to a messages catalogue is future work.
4. **Browser E2E suite** — recommend adding Playwright for the four role
   journeys before public launch.
5. **Volumetric DoS / WAF** — app limiters done; edge protection is infra.
6. **Prometheus/Sentry agents** — hooks documented, not yet wired to a backend.

## Deployment requirements

| Component | Required | Config |
| --- | --- | --- |
| PostgreSQL 14+ | ✅ | `DB_*`; `composer test:pg` profile |
| Redis | ✅ queues/cache | `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` |
| Queue workers | ✅ | `php artisan queue:work` (≥2) |
| Scheduler | ✅ | `* * * * * php artisan schedule:run` |
| HTTPS | ✅ | `APP_URL=https://…`, `FORCE_HTTPS=true`, `FORCE_HSTS=true` (after TLS verified) |
| Paynow | ✅ | `PAYNOW_INTEGRATION_ID/KEY/RESULT_URL/RETURN_URL/TEST_MODE=false` |
| Secrets store | recommended | env today → migrate to AWS Secrets Manager/Vault (see report doc) |
| Backups | ✅ | `docs/BACKUP_AND_RESTORE.md` — pg_dump + WAL + storage sync, quarterly restore drill |

## 2026-10-03 — MySQL migration + login fix + full demo dataset

**Admin login bug — root cause found & fixed:** seeded users had
undocumented passwords (`Platform#2026`/`Password#2026`) that no docs or
UI referenced — every login with `password` failed despite an active
user row. `DatabaseSeeder` now assigns the deterministic demo password
(`password`), sets `email_verified_at`, uses `updateOrCreate`
(idempotent), and refuses to run in production without an explicit
`SEED_DEMO_IN_PRODUCTION=1` override.

**MySQL is now the primary DB** (was pgsql): `.env`/`.env.example` →
mysql, `docker-compose.yml` added (mysql:8.4 + redis). Compat sweep
clean — no pgsql-specific SQL existed (all raw SQL is portable:
lower(), count/having, 1=0). **Concurrency re-verified on MySQL 8.0.33:**
8-way parallel same-seat race → exactly one winner (`FOR UPDATE` holds).
Full suite: **67 tests / 231 assertions pass on MySQL** (also on sqlite
memory).

**Demo dataset** (docs/DEMO_DATA.md): platform admin + 2 operators
(Zimbabwe Express, Bulawayo Star) with all staff roles, 33 ZW locations,
14 routes, ~58 trips across today→+7 days, 4 buses on generator-built
layouts (49/45/49/30 seats), bookings in confirmed/pending/cancelled
states, matching payments, tenant-scoped promos (WELCOME10/BULAWAYO5).

**New commands:** `transithub:seed-demo` (destructive-reset demo setup)
and `transithub:health` (env+db+seed+paynow diagnostics, exit 1 on
failure).
