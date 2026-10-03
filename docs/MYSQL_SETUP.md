# MySQL Setup

TransitHub's primary database is **MySQL 8.x** (verified on 8.0.33; `mysql:8.4` via Docker also supported). SQLite remains the default for the lightweight test suite (`phpunit.xml` pins `:memory:`).

## Quick start

```bash
# 1. Database — Docker:
docker compose up -d mysql
#    or point .env at any MySQL 8+ instance:
#    DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD

# 2. Configure
cp api/.env.example api/.env
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=transithub
# DB_USERNAME=transithub
# DB_PASSWORD=…

cd api && composer install && php artisan key:generate

# 3. Migrate + seed the full demo dataset
php artisan migrate:fresh --seed          # or: php artisan transithub:seed-demo

# 4. Verify
php artisan transithub:health
```

## Environment

| Variable | Default | Notes |
|---|---|---|
| `DB_CONNECTION` | `mysql` | primary driver |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | local dev: DBngin often runs on `3310` |
| `DB_DATABASE` | `transithub` | |
| `DB_USERNAME` / `DB_PASSWORD` | `transithub` / *(empty)* | never commit real credentials |

Charset/collation: `utf8mb4` / `utf8mb4_unicode_ci` (docker-compose sets it server-side; for a pre-existing DB, `ALTER DATABASE transithub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`).

## Concurrency

Seat holds lock `trip_seat_legs` rows with `SELECT … FOR UPDATE` inside a transaction — verified on MySQL with a real 8-way parallel booking race (exactly one winner). Use InnoDB (default on MySQL 8) — MyISAM has no row locking and is unsupported.

## Running the test suite on MySQL

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3310 \
DB_DATABASE=transithub_test DB_USERNAME=transithub DB_PASSWORD=transithub \
php artisan test
```

The suite passes on MySQL 8.0.33 — 67 tests / 231 assertions.
