# Pricing Engine

`PricingService::quote(trip, passengers[], seats[], boardingIdx, dropoffIdx, promoCode?)`

## Inputs (all server-side, never client-trusted)

- **Base fare**: `route_fares` rows keyed by (route, boarding_stop_index,
  dropoff_stop_index, passenger_type) falling back to route default fare —
  so Harare→Kwekwe can differ from Harare→Bulawayo (§90 different fares
  between stops).
- **Seat modifier**: `seat_layout_seats.price_modifier` (premium/VIP).
- **Passenger type**: adult | child | senior — discounted via
  `tenant_booking_rules` (`child_discount_percent`, …).
- **Promotions**: `PromotionService` resolves code → discount (percent or
  fixed), subject to route/tenant/date/usage limits and caps
  (min spend, max discount, per-customer and global usage).
- **Fare rules**: `open` (flexible) vs `dated` ticket type multiplier
  where enabled by tenant.

## Output

```
{
  currency, subtotal, seat_modifiers, discount,
  total, per_passenger_breakdown[], promotion {code, saving}?
}
```

The booking stores `subtotal`, `discount_amount`, `total`, `currency`
(immutable snapshot) plus a `pricing_snapshot` JSON for auditability.

## Rules engine (`tenant_booking_rules`)

JSON-backed per-tenant settings with typed accessors:

- `max_seats_per_booking`, `seat_hold_minutes` (default 10)
- `booking_cutoff_minutes` (stop sales N min before departure)
- `min_booking_age_hours` (advance purchase limit)
- `cancellation_policy`: tiered windows, e.g.
  `[{">=48h":100},{"24-48h":50},{"<24h":0}]`
- `passenger_fields`: which doc fields are required
- `cash_seat_policy`: whether unpaid cash bookings keep seats held/booked
- `baggage`, `child/senior` discounts, `open_ticket_multiplier`

Nothing tenant-specific is hard-coded; validation failures produce
remediation messages (§58).
