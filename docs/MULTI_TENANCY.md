# Multi-Tenancy

## Model

One platform, many operators. Tenant = a bus company (`tenants` table).
All tenant-owned rows carry `tenant_id`; a `tenant_users` pivot assigns
users to tenants with a role set. Users belong to exactly one tenant
except platform staff (`users.is_platform_user = true`, `tenant_id` null).

## Resolution

- **API (admin/agent/finance/support)**: tenant comes from the
  authenticated user's `tenant_id` — never from a request parameter.
- **Public booking API**: tenant resolved from the request host —
  `{slug}.transithub.test` subdomain today; `custom_domain` column reserved
  for `book.operator.com` (CNAME) later with no schema change.
- Resolution is done once in `ResolveTenant` middleware; the `Tenant`
  instance is bound in the container (`app('tenant')`) and set as the
  spatie permission team.

## Enforcement layers (defence in depth)

1. **Query level** — `BelongsToTenant` trait adds a global scope
   `where tenant_id = current tenant` on every tenant model. Queries that
   legitimately cross tenants (platform admin) use `->withoutTenantScope()`.
2. **Route level** — `tenant` middleware 404s tenant routes when no tenant
   is resolvable; tenant-scoped bindings resolve through the scope.
3. **Authorization level** — policies receive the tenant implicitly (the
   model is already scoped); spatie teams keep permissions per-tenant.
4. **API level** — public endpoints never accept `tenant_id`; admin
   endpoints derive it server-side.
5. **Jobs** — queued jobs serialise `tenant_id` and re-bind tenant context
   on execution (`TenantAwareJob` base); a job can never run "untenanted".
6. **Cache** — keys namespaced `tenant:{id}:…` via a `TenantCache` helper.
7. **Storage** — files under `tenants/{tenant_id}/…`; media validates
   ownership through the tenant-scoped `tenant_files` table.
8. **Reports** — all report queries go through tenant-scoped models;
   platform reports explicitly use `withoutTenantScope` and require the
   `platform.reports` permission.

## Tenant lifecycle

`pending → active ⇄ suspended → archived`. Suspended tenants: public
booking returns a friendly unavailable page; logins rejected except
platform admins; scheduled jobs skip the tenant.

## Testing the isolation

`tests/Feature/TenantIsolationTest` proves: Tenant B cannot read or write
Tenant A's bookings/passengers/buses/routes/payments/reports via API,
cannot replay Tenant A's idempotency keys, cannot see Tenant A's files,
and background jobs do not cross tenants. Any new tenant-owned model must
use `BelongsToTenant` or the architecture test
(`tests/Feature/TenantScopeArchitectureTest`) fails.
