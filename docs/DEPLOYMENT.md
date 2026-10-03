# Deployment

## Topology

```
            ┌─────────────┐
            │   CDN / TLS │  (Cloudflare/ALB)
            └──────┬──────┘
        ┌──────────┼───────────┐
   web/ Next.js (Node, behind CDN)
   api/ Laravel on Nginx+PHP-FPM (≥2 replicas)
        ├── php artisan queue:work (systemd/supervisor fleet)
        ├── php artisan schedule:run (cron * * * * *)
PostgreSQL (managed) + Redis (managed) + S3-compatible storage
```

## Environments

`local | testing | staging | production` via `APP_ENV`; `.env.example`
documents every variable (no secrets committed). Per-env `config/` only —
never code branches on env name.

## Provision checklist

- PHP 8.3 FPM, composer `--no-dev -o`, `artisan migrate --force`,
  `config:cache route:cache event:cache view:cache`.
- Node 22 build: `npm ci && npm run build`; serve via `next start` or
  static export behind CDN where applicable.
- Queue workers: `queue:work --queue=high,default,low --tries=3
  --backoff=5 --max-time=3600` under supervisor; failed jobs to
  `failed_jobs` with alerting.
- Scheduler: single cron entry; all scheduled jobs idempotent (§71).
- Storage: `FILESYSTEM_DISK=s3` in prod, bucket per env, `tenants/` prefix.
- TLS everywhere; HSTS; security headers (SECURITY.md).
- Postgres: TLS, least-privilege user, PITR backups + nightly dump;
  Redis: persistence optional (cache/queue), separate logical DBs.
- Backups: DB PITR + nightly, storage bucket versioning; quarterly
  restore drill (documented in OPERATIONS.md).

## CI/CD (`.github/workflows/ci.yml`)

```
lint (pint --test, eslint, tsc) → phpstan/larastan → unit+feature tests
(Postgres service) → concurrency group → E2E (Playwright) →
npm audit + composer audit → build (vite/next build + artisan) → deploy
```

Deploy step gated on all green; `staging` auto-deploys `develop`,
`production` deploys `main` with tagged release + rollback =
re-deploy previous tag + `migrate:rollback` only where safe.

## Health & observability

- `GET /health` (liveness), `GET /health/ready` (db+cache+queue pings;
  no internals leaked — status strings only).
- Structured JSON logs → aggregator; Sentry/Bugsnag DSN optional envs.
- Metrics hooks: queue depth, failed jobs, payment failure rate, hold
  expiry rate.
