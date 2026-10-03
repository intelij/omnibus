# Troubleshooting

## Local setup

| Symptom | Fix |
|---|---|
| `php` not found | Use Herd: `"$HOME/Library/Application Support/Herd/bin/php"` |
| `Class ...TenantScope not found` | `composer dump-autoload` |
| SQLite `database is locked` in tests | tests use `:memory:`; for file DB set `DB_CONNECTION=sqlite` + busy_timeout |
| Node version | `nvm use 22` (`.nvmrc` provided) |
| QR/ticket fonts/PDF error | `composer install` then check `storage/` writable |

## Runtime

| Symptom | Fix |
|---|---|
| Every request 404 tenant | Host must be `{slug}.{base}` or authenticated tenant user; check `APP_DOMAIN` |
| All admin calls 403 | User has no role in this tenant — check `model_has_roles` team_id |
| Seats stuck `held` | `ReleaseExpiredSeatHoldsJob` — is scheduler running? `schedule:run` |
| Payment stuck `pending` | `payment_transactions` for provider events; run `ReconcilePaymentsJob` manually |
| Duplicate notifications | check jobs are idempotent; `processed` flags on notification rows |
| Booking 409 `seats_unavailable` | seat state/segment conflict — fetch fresh seat map |
| Idempotency 409 `key_reuse` | same key + different payload — client must regenerate key per intent |

## Debugging aids

- `tail -f storage/logs/laravel.log` (structured, scrubbed)
- `php artisan tinker` with `tenancy()`/`app('tenant')` context
- `DB::listen` / `laravel/pail` for N+1 hunting
- `php artisan about` for env/driver summary
- Audit log table for "who did what" before touching data
