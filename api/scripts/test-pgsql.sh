#!/bin/sh
# Full suite against real PostgreSQL — FOR UPDATE locking, unique
# constraints and query plans verified under production semantics.
# Requires a local Postgres; override DB_* via env as needed.
exec env \
  DB_CONNECTION=pgsql \
  DB_HOST="${DB_HOST:-127.0.0.1}" \
  DB_PORT="${DB_PORT:-5432}" \
  DB_DATABASE="${DB_DATABASE:-transithub_test}" \
  DB_USERNAME="${DB_USERNAME:-${USER}}" \
  DB_PASSWORD="${DB_PASSWORD:-}" \
  php artisan test "$@"
