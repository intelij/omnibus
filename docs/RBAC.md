# RBAC

## Implementation

`spatie/laravel-permission` with **teams enabled; team = tenant**.
Roles/permissions are seeded globally; assignment is per tenant via
`tenant_users` + `model_has_roles (team_id = tenant_id)`.

## Roles

| Role | Scope | Notes |
|---|---|---|
| `platform_super_admin` | global | manages tenants, platform config, audits. Never assigned inside a tenant. |
| `tenant_owner` | tenant | everything incl. billing/branding/payment config |
| `tenant_admin` | tenant | ops admin; no platform ownership actions |
| `booking_agent` | tenant | create/manage bookings, collect cash, print tickets |
| `finance_officer` | tenant | payments, reconciliation, refunds, exports |
| `support_agent` | tenant | lookups, notes, support tickets, resend tickets |

## Permissions (granular, dot-notated)

```
bookings.view|create|update|cancel|refund|export
payments.view|reconcile|refund|export|confirm_cash
buses.view|create|update|delete
seat_layouts.view|create|update|delete
routes.view|create|update|delete
schedules.view|create|update|cancel
trips.view|update|cancel|manifest
users.view|create|update|disable
reports.view|export
promotions.view|create|update|delete
support.view|create|update|assign|close
settings.view|update        branding.view|update
payments.configure          tenants.manage (platform)
audit.view                  tickets.verify|resend
```

Role → permission matrix lives in `database/seeders/RolesAndPermissionsSeeder.php`
and is the single source of truth.

## Enforcement

- Policies per model (`BookingPolicy`, `BusPolicy`, …) — every controller
  calls `authorize()`. Frontend hides what you can't do; backend
  *denies* it.
- `permission:` middleware on route groups for coarse gating.
- Feature flags gate features, never permissions.
- Sensitive actions (cash confirmation, refunds, role changes, branding,
  payment config) require their own permission and are audit-logged.

## Denial examples (tested)

- `booking_agent` calling `POST /payments/{id}/confirm` → 403.
- `support_agent` reading a payment row → masked/absent.
- `finance_officer` deleting a bus → 403 (needs `buses.delete`).
- Any tenant role hitting platform tenant-management routes → 403.
