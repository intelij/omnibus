# Booking Engine

## Booking states

```
draft                — being composed server-side (rarely persisted)
held                 — seats held, awaiting passenger details
pending_payment      — payment initiated, awaiting provider/cash
confirmed            — paid (or cash-authorised), ticket issued
completed            — trip departed/completed
cancelled            — passenger/agent/operator cancelled
expired              — hold or pending payment timed out
refunded             — fully refunded after cancellation
```

`BookingStateMachine` defines legal transitions; services call
`transition($booking, 'confirmed')`, which validates, writes history
(`booking_events`), and emits a domain event. Status is never set directly.

## Create flow (`BookingService::create`)

```
Validate request (FormRequest)
  → resolve tenant (host) / trip (scoped)
  → validate stops exist on route, boarding < dropoff
  → Idempotency-Key middleware (replay-safe POST /bookings)
  → DB::transaction:
      lock trip row
      validate passenger count vs rules (max seats per booking)
      hold seats  (explicit ids OR auto-allocate) — SEAT_ENGINE
      pricing     (PricingService: fare rules + seat modifiers + segment)
      promotion   (PromotionService: validate + reserve redemption)
      insert bookings + booking_passengers + booking_seats (per pax/seat)
      booking_events: created, seats_held
  → PaymentService::initiate(booking, method) → payment row + instructions
  → queue notifications (booking created / payment instructions)
```

POST /bookings is idempotent: same `Idempotency-Key` replays the original
response; concurrent submissions collapse to one booking.

## Entity split

- `bookings` — header: reference, trip, status, totals, currency,
  contact, idempotency key, promo.
- `booking_passengers` — one row per passenger (name, contact, doc fields
  only when the tenant's rules require them).
- `booking_seats` — passenger ↔ trip_seat ↔ segment indexes + per-seat fare.
- `booking_events` — append-only history of every transition.

## Reference

`TH-` + 6 chars from Crockford base32 (no O/0/I/1 confusion), unique index,
retry loop — e.g. `TH-7F4K92`.

## Cancel / expire

`BookingService::cancel(booking, actor, reason)`:
state machine check → refund amount via tenant cancellation policy
(REFUNDS.md) → release seats → refund via PaymentService if paid →
events + notifications. Expiry jobs handle `held` and `pending_payment`
timeouts identically to release the seats.

## Operational edge cases handled

- Refresh/back/multi-tab: idempotency keys + hold tokens.
- Payment after hold expiry: confirm path re-takes or routes to review.
- Webhook retries: `provider_event_id` unique dedup.
- Double-click Pay: `pending_payment` is a guard state; second call 409s.
- Bus replacement: `TripService::swapBus` rematerialises `trip_seats`,
  re-seats booked passengers (same label if present else auto-allocate),
  emits `trip.seat_reassigned` notifications.
- No-show / boarding: `tickets.status → used` via QR verify endpoint.
