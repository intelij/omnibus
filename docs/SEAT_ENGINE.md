# Seat Engine

## Layout model (data-driven, operator-defined)

```
seat_layouts (tenant_id, name, decks[], status)
seat_layout_seats (
  tenant_id, layout_id, deck, row, col,        -- grid position
  label ('12A'), seat_number (int),
  type: standard|premium|vip|disabled_access|staff|driver|toilet|kitchen|stairs|exit,
  position: window|aisle|middle|none,
  price_modifier numeric,                      -- e.g. +5.00 for premium
  is_bookable bool,                            -- exits/toilets are not
  sort_order
)
buses.seat_layout_id → seat_layouts
```

`row`/`col` are logical grid coordinates; the designer renders them and the
frontend seat map consumes the same JSON. Layouts are versioned: a published
trip snapshots its seat rows, so editing a layout never corrupts live trips.

## Trip inventory

When a trip is published we materialise `trip_seats`:

```
trip_seats (
  id, tenant_id, trip_id, seat_id, label, type, price_modifier,
  state: available|held|booked|blocked|maintenance,
  held_until nullable, hold_token nullable, booking_id nullable,
  unique(trip_id, seat_id)
)
```

The seat is *uniquely* a row per trip — the DB row itself is the lockable
unit of inventory (ADR-004).

## States

```
available ──hold──▶ held ──confirm──▶ booked
   ▲                 │                    │
   └──release(expiry/cancel/timeout)◀─────┘ (cancel frees the seat)
blocked / maintenance: operator-controlled, never bookable
```

## Concurrency protocol

`SeatInventoryService::hold(trip, seatIds, boardingIdx, dropoffIdx, ttl)`:

1. `DB::transaction`
2. `SELECT … FOR UPDATE` the requested `trip_seats` rows (ordered by id to
   avoid deadlocks).
3. Re-check each row: `state = available` OR (`state = held` AND
   `held_until < now()` AND owned by a different token → steal-expired)
   AND **no overlapping segment booking** (below).
4. `UPDATE` → `held`, `held_until = now()+ttl`, `hold_token`.
5. Any failure → `SeatUnavailableException` → HTTP 409.

Because the `SELECT FOR UPDATE` serialises contenders on the same rows and
the state check happens *inside* the lock, two simultaneous holds on one
seat cannot both succeed. The `unique(trip_id, seat_id)` index plus the
atomic `UPDATE … WHERE state='available'` guard is the second line of
defence for direct inserts.

## Segment-aware occupancy (route stops)

`booking_seats` stores `boarding_stop_index` and `dropoff_stop_index`
(positions in `route_stops`). A seat is free for segment `[a,b)` if no
active booking occupies `[x,y)` where `x < b AND a < y`.
Harare→Kwekwe frees the seat for Kwekwe→Bulawayo (§91).

`availableForSegment()` runs the overlap check inside the hold transaction
so segment reuse is race-safe too.

## Hold expiry

- `held_until` timestamp on the row; `ReleaseExpiredHoldsJob` runs every
  minute and sweeps `held AND held_until < now()`.
- Lazy release: any read of a seat map also filters `held_until < now()`,
  so expired seats never display as held even between sweeps.
- Payment confirmation re-validates the hold (`held` by this booking and
  not expired); if it expired and the seat is still free it is re-taken
  atomically, else payment proceeds to manual review — never silently
  double-books.

## Automatic allocation

`AutoAllocator::allocate(trip, count, preference, segment)`:

- `together` — prefer same row; else contiguous columns; else nearest rows.
- `front|rear` — ascending/descending row order.
- `window|aisle` — filter `position`.
- `any` — first N seats by `sort_order`.

All filtered to `state=available` **and** segment-free **and**
`is_bookable`, inside the same lock protocol. Allocation never touches
held/booked/blocked rows.

## Frontend contract

`GET /trips/{id}/seats?boarding=i&dropoff=j` returns the grid with
per-seat `state` for the requested segment plus `price_modifier`; the React
seat map renders a legend with distinct shapes (not colour-only, §53).
