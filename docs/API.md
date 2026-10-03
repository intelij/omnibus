# API

Base: `/api/v1`. JSON in/out. Sanctum bearer tokens (admin) or
session/cookie (first-party SPA). Public booking endpoints resolve the
tenant from host/subdomain.

## Conventions

- **Errors**: `{ "message": "...", "errors": {field: [msg]} }` (422);
  `{ "message": "..." }` for 401/403/404/409/429.
- **Pagination**: `?page=&per_page=` → `{ data: [], meta: {current_page, last_page, per_page, total}, links }`.
- **Filtering**: `?status=`, `?date_from=`, `?date_to=`, `?q=`
- **Sorting**: `?sort=-departure_at` (minus = desc).
- **Idempotency**: `Idempotency-Key: <uuid>` header on POST bookings,
  payments, refunds.
- **Versioning**: URI (`/api/v1`); breaking changes → `/api/v2`.

## Endpoint map

```
# auth (admin/staff)
POST   /auth/login            POST /auth/logout          GET /auth/me
POST   /auth/forgot-password  POST /auth/reset-password

# platform (platform_super_admin only)
GET/POST /platform/tenants    PATCH /platform/tenants/{t}/status
GET      /platform/audit-logs GET    /platform/reports/overview

# public booking (tenant host)
GET    /public/routes                              # searchable origins/destinations
GET    /public/trips/search?from=&to=&date=&passengers=
GET    /public/trips/{trip}                        # details + fare + policy
GET    /public/trips/{trip}/seats?boarding=&dropoff=
POST   /public/bookings                            # Idempotency-Key; seats or auto
GET    /public/bookings/{reference}?email=         # retrieve/manage
POST   /public/bookings/{reference}/cancel
GET    /public/bookings/{reference}/ticket         # printable ticket + QR
POST   /webhooks/payments/{gateway}                # provider callbacks

# tenant admin (authenticated, tenant-scoped)
CRUD   /buses /seat-layouts /routes /routes/{r}/stops /schedules /trips
GET    /trips/{t}/manifest     POST /trips/{t}/status   POST /trips/{t}/swap-bus
GET/POST /bookings             GET /bookings/{b}        POST /bookings/{b}/cancel
POST   /bookings/{b}/resend-ticket   POST /tickets/verify
CRUD   /promotions             CRUD /users (staff)
GET    /payments               POST /payments/{p}/confirm   # cash
POST   /payments/{p}/refund    GET /payments/export
CRUD   /support-tickets        GET /reports/{revenue|occupancy|bookings|agents}
GET/PATCH /settings/branding   GET/PATCH /settings/rules
GET/PATCH /settings/payments   GET /onboarding/progress
GET    /audit-logs             GET /notifications
```

## OpenAPI

Generated spec served at `/api/docs` + `openapi.json` (hand-maintained
`docs/openapi.yaml` kept in sync by CI check). Every endpoint documents
auth, request, responses, validation errors, idempotency.

## Throttling

`auth/*` 5/min/IP+login; public booking 60/min; payments 30/min;
webhooks unthrottled but signature-gated.
