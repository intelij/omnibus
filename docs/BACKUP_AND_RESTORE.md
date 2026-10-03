# Backup & Restore

TransitHub's crown jewels are in PostgreSQL — bookings, payments, audit logs,
tenant configuration. Uploaded tenant files (logos, documents) live on the
`public` disk. Everything else is code or cache.

## What to back up

| Store            | Contents                                        | Method                | RPO target |
| ---------------- | ----------------------------------------------- | --------------------- | ---------- |
| PostgreSQL       | tenants, bookings, payments, seats, audit, PII  | `pg_dump` + WAL archiving | ≤ 15 min |
| `storage/app`    | tenant uploads (logos, docs), generated tickets | file sync / S3        | ≤ 24 h |
| `.env` / secrets | never in backups — store in the secret manager  | secret store          | —          |

## Scheduled backup (reference)

```sh
# Daily full dump, gzip'd, 30-day rotation
0 3 * * * pg_dump -Fc "$PGDATABASE" -f /backups/transithub-$(date +\%F).dump \
  && find /backups -name 'transithub-*.dump' -mtime +30 -delete

# Continuous: enable WAL archiving for point-in-time recovery
# postgresql.conf: wal_level=replica, archive_mode=on,
# archive_command='test ! -f /walarchive/%f && cp %p /walarchive/%f'
```

Recommended production: managed Postgres (RDS/Cloud SQL) with automated
snapshots + WAL/PITR enabled and snapshots copied to a second region.

## Restore drill (run quarterly)

```sh
# 1. Spin up an isolated database
createdb transithub_restore

# 2. Restore the dump (custom format = selective, parallel-safe)
pg_restore -d transithub_restore -j4 /backups/transithub-2026-10-03.dump

# 3. Point the app at it and verify key invariants
DB_DATABASE=transithub_restore php artisan tinker
>>> App\Models\Booking::count();   # expect ~snapshot count
>>> App\Models\Payment::where('status','paid')->count();
>>> App\Models\AuditLog::latest()->first()->created_at;  # sanity recency

# 4. File storage: restore the backup dir over storage/app
rsync -a /backups/storage-app/ storage/app/
```

Verify in the drill: booking counts, a known booking reference resolves,
ticket QR tokens still verify, audit log tail matches backup time.

## Disaster recovery

- **RTO target**: 4 h (restore + redeploy + DNS).
- **RPO target**: 15 min (WAL) / 24 h (files).
- Document the on-call restore owner in the ops runbook; restoring is
  `pg_restore` + `php artisan migrate --force` (no-op if current) +
  `config:cache` + queue/worker restart.
- **Never restore over a live database** — restore to a new name, verify,
  then cut over connection strings.

## Audit-log retention

| What                    | Policy                                                        |
| ----------------------- | ------------------------------------------------------------- |
| `audit_logs` rows       | Retain ≥ 7 years (financial-compliance norm); never delete via UI — append-only, no update/delete path in code or UI |
| Export                  | Admin → `GET /api/v1/audit-logs` (filtered); DB-level CSV dump for regulator exports |
| Access                  | Tenant admins read own tenant's log; platform reads all; no delete permission exists for any role |
| Storage sizing          | ~1 KB/row; ~1M rows/yr ≈ 1 GB/tenant — archive >2y rows to cold storage table if volume demands |

## PII retention (linked policy)

- `pii_retention_days` tenant rule (default **730**) drives the daily
  `AnonymizeOldBookingDataJob` sweep — identifying fields on settled
  bookings are masked (`REDACTED`/null), financial rows are retained.
- Guests can self-serve export (`GET public/bookings/{ref}/privacy-export`)
  and anonymization (`POST …/anonymize`) — both gated by the booking's
  contact key, both audited.
