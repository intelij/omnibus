# Security Test Matrix

Status key: ✅ verified by automated test or manual reproduction · ⚠️ partial /
environment-dependent · ⬜ requires external tooling (recommended next step).

## Injection & request abuse

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| SQL injection in search/booking params | Parameterized queries only | All input via Eloquent/validation; no raw concat found in audit | ✅ code review |
| XSS via passenger names / support notes | Escaped output | React JSX escapes by default; no `dangerouslySetInnerHTML` in app | ✅ code review |
| CSRF on state changes | Stateless API — bearer tokens, no cookie auth on `api/*`; Sanctum tokens only | ✅ design + review |
| Rate-limit brute force on login | 5/min per email+IP → 429 | `test_login_rate_limit_returns_429` | ✅ automated |
| Search/seats scraping flood | 60/min per IP | Named `public-search` limiter verified under 50-request burst | ✅ load test |
| Booking endpoint flood | 20/min per IP | `bookings` limiter engaged during 20-request race | ✅ load test |
| Promo validation abuse | 30/min | `promo-validation` limiter wired | ✅ review |
| Webhook burst from provider | 120/min cap; signature + dedup carry the real weight | `webhook` limiter wired | ✅ review |

## Authentication & session

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Token forgery | Sanctum hashed tokens | Standard Sanctum; `auth:sanctum` on all staff routes | ✅ automated (RBAC suite) |
| Disabled user reuse of token | Blocked | `EnsureActiveUser`/policy checks — `disabled user is blocked` test | ✅ automated |
| Password in logs/audit | Never logged | Validation strips password; `Mask` redacts sensitive keys | ✅ automated (`audit_metadata_masks_pii`) |
| Password reset flood | 5/min | `password-reset` limiter wired | ✅ review |

## Tenant isolation (IDOR sweep)

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Cross-tenant list resources | Scoped out | `tenant user cannot list other tenants resources` | ✅ automated |
| Direct ID access to other tenant's booking/payment/trip/bus | 404/403 | `cannot read other tenants models/booking/payments` | ✅ automated |
| Public host spoofing (host A sees tenant B data) | Host/tenant resolution rejects | `public booking never leaks across hosts` | ✅ automated |
| Route-model-binding bypass | Tenant context bound BEFORE substitution (middleware priority) | Ordering enforced in `bootstrap/app.php` + tests | ✅ automated + review |
| Suspended tenant access | Blocked | `suspended tenant blocks all access` | ✅ automated |
| Platform data via tenant token | `is_platform_user` + team-0 roles required | `platform admin sees all…` test | ✅ automated |
| `tenant_id` in request body | Ignored — derived from principal/host, never trusted | All writes set `tenant_id` server-side | ✅ review |

## Privilege escalation (RBAC)

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Booking agent reads finance reports | 403 | `support agent cannot view payments`; agents blocked from finance perms | ✅ automated |
| Finance manages fleet/users | 403 | `finance cannot delete buses or manage users` | ✅ automated |
| Tenant admin grants platform role | 403 | `tenant admin cannot assign platform role` | ✅ automated |
| Guest self-confirms cash payment | No public confirm path; `payments.confirm_cash` permission required | `booking agent cannot confirm cash payment` | ✅ automated |
| Promotion create/edit by agent | `promotions.*` perms — ops/finance split | Policy-enforced in controller | ✅ review |

## Payment & webhook abuse

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Forged webhook signature | 401 before state change | `webhook_bad_signature_rejected` (HMAC); Paynow SHA512-hash verified | ✅ automated |
| Duplicate webhook replay | No-op via `(payment_id, provider_event_id)` unique | `duplicate_webhook_is_deduplicated` | ✅ automated |
| Paid callback with wrong amount | Discrepancy flagged, NOT confirmed | `paid_webhook_with_wrong_amount_flags_discrepancy` | ✅ automated |
| Payment arrives after booking expired | Paid flagged `late_payment`, booking stays expired — controlled refund path | `payment_arriving_after_booking_expired` | ✅ automated |
| Double-pay / retried initiation | Same open payment reused | `double_pay_click_creates_single_payment` | ✅ automated |
| Method-switch double-pay | Prior open payment cancelled | `switching_payment_method_cancels_previous` | ✅ automated |
| Tampered Paynow initiate response | Hash of Paynow response verified before redirecting customer | `CardGateway::initiate` hash check | ✅ review |
| Secrets in responses/logs | Never returned; `Mask` in audit metadata | ✅ automated |

## Booking & seat races

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Two buyers, same seat, same time | Exactly one wins | 8-parallel-request race on **PostgreSQL**: 1×201, 7×409 | ✅ load-verified PG |
| Same seat via agent + customer | One wins | `two_agents_same_seat…` + leg-row locks | ✅ automated |
| Promo with usage_limit=1 on two trips | One redemption | `inventory_limited_promo…` + `lockForUpdate` on promo row | ✅ automated (race fix applied) |
| Expired-hold seat rebooking | Released → new owner wins; old payment can't resurrect | `payment_after_hold_expiry_never_double_books` | ✅ automated |

## Transport & headers

| Attack | Expected | Actual | Status |
| --- | --- | --- | --- |
| Plain-HTTP production traffic | `FORCE_HTTPS` redirects via `URL::forceHttps()` + edge TLS | Env-gated; requires `APP_URL=https://…` | ⚠️ deployment-dependent |
| Clickjacking | `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` | `security_headers` test | ✅ automated |
| MIME sniffing | `X-Content-Type-Options: nosniff` | ✅ automated |
| HSTS stripping | `FORCE_HSTS` emits header on https | Env-gated — enable AFTER TLS verified at edge | ⚠️ deployment-dependent |
| Upload of executable/script | MIME sniff + allowlist + UUID name + tenant-isolated path | FileController review | ✅ review |

## Residual items for a dedicated pen-test

⬜ Burp/ZAP scan of the full API surface · ⬜ Paynow IP allowlisting (publish
ranges not publicly documented — verify with Paynow support before
allowlisting) · ⬜ Dependency audit (`composer audit`, `npm audit`) in CI ·
⬜ Secrets-rotation drill · ⬜ DoS at edge (CDN/WAF) — app-level limiters are
in place but volumetric protection is infrastructure's job.
