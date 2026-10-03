# Security

## Authentication

- Sanctum tokens for API/SPA/mobile; `abilities` not used — real RBAC.
- bcrypt password hashing; policy ≥10 chars, mixed classes, breached-list
  check (`NotPwnedVerifier` where network allows — documented flag).
- Login throttling per IP+login; lockout via `throttle` middleware.
- Password reset: signed, expiring, single-use tokens.
- MFA-ready: `users.totp_secret` column reserved; enforcement flag per
  tenant (`security.require_2fa`) — ship shape, enable when configured.

## Authorisation & isolation

- Policies everywhere; permission strings per RBAC.md.
- Tenant scoping at query+middleware+job+cache+storage layers
  (MULTI_TENANCY.md). IDOR tests prove cross-tenant 404s.

## Transport/HTTP

- Security headers middleware: `X-Content-Type-Options`, `X-Frame-Options`
  (or frame-ancestors CSP), `Referrer-Policy`, `Permissions-Policy`, CSP
  on the Next.js app.
- CORS: explicit allowlist (passenger origins + admin origin), credentials
  scoped; never `*` with credentials.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax` (Sanctum SPA mode).

## Input safety

- FormRequests + Zod on the client (client validation is UX only).
- Eloquent parameter binding everywhere; no raw interpolated SQL.
- Mass-assignment via `$fillable` + DTOs; no `Model::create($request->all())`.
- Output escaping: React default; Blade none in API app.
- File uploads: MIME sniff (finfo) + extension + size limits; stored
  outside public root under `tenants/{id}/`; SVG blocked or sanitised;
  filenames regenerated (uuid); never trust client names (§64).

## Payments & webhooks

- No card data at rest (PAN/CVV never stored — §28).
- Webhook signature verification; `provider_event_id` dedup; audit of
  every payload hash.
- Server-side pricing always recomputed — client totals ignored (§45).

## Abuse resistance

- Rate limits per API.md; stricter on auth + public booking create.
- Idempotency keys on all mutating money endpoints.
- Signed URLs for private file downloads (ticket PDFs, exports).

## Secrets & logs

- Secrets only in env / secret manager; `.env.example` ships empty.
- Structured logs scrub `password`, `token`, `authorization`, card fields
  via a `LogScrubber` tap. No secrets/PII beyond necessity in logs.
- Audit log is append-only (application has no UPDATE/DELETE path).

## Sessions & tokens

- Token TTL configurable; `currentAccessToken()->delete()` on logout;
  `tokens.expires_at` honoured. Password change revokes all tokens.

## Dependency security

- `composer audit` + `npm audit` in CI; `roave/security-advisories` in
  require-dev. Pinned deps; no `latest`/`*` ranges.
