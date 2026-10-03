# Authentication

Token-based auth via Laravel Sanctum — no cookies/session for API clients; the Next.js app stores the bearer token.

## Login

```
POST /api/v1/auth/login
{ "email": "…", "password": "…" }
→ 200 { "token": "…", "user": {…, "roles": […]} }
→ 422 { "errors": { "email": ["The provided credentials are incorrect."] } }
→ 423 on suspended tenant, 422 on disabled/inactive user
```

Rate-limited to 5 attempts/minute per email+IP (named `login` limiter — 429 on excess).

## What the login path checks

1. Credentials (`Hash::check` — seeded passwords are bcrypt-hashed, never stored plaintext)
2. Account `status === 'active'` (disabled/blocked users rejected)
3. Tenant `status === 'active'` when the user belongs to one (suspended tenants rejected)
4. Tenant context is bound so **Spatie team-scoped roles** resolve correctly — `roles` in the response are the *tenant's* roles
5. Issues a 12-hour personal access token

Platform users (`is_platform_user`) get roles in team "0" (outside all tenants) — `platform_super_admin` can act on any tenant via `X-Tenant` impersonation or the platform endpoints.

## Demo accounts

See `docs/DEMO_DATA.md` — `admin@transithub.test / password` etc. Development-only; the seeder refuses production.

## Past bug (fixed)

Seeded users previously used undocumented passwords (`Platform#2026`/`Password#2026`) — every "password"-based login failed despite docs implying otherwise. `DatabaseSeeder` now uses `updateOrCreate` + the deterministic `password` for every seeded account, sets `email_verified_at`, and `AuthLoginTest` proves the seeded credentials authenticate end-to-end.
