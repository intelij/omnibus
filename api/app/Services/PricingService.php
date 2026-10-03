<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripSeat;
use Illuminate\Support\Collection;

/**
 * Authoritative server-side pricing. Client-provided totals are never
 * trusted — the API recomputes and compares.
 */
class PricingService
{
    /**
     * @param Collection<int, TripSeat> $seats
     * @param list<array> $passengers passenger payloads with passenger_type
     * @return array{currency:string, subtotal:float, seat_modifiers:float, total_before_discount:float, breakdown:list<array>}
     */
    public function quote(Trip $trip, Collection $seats, array $passengers, int $boardingSeq, int $dropoffSeq): array
    {
        $tenant = $trip->tenant;
        $currency = $tenant->currency;
        $route = $trip->route;

        $breakdown = [];
        $subtotal = 0.0;
        $seatModifiers = 0.0;

        $seatList = $seats->values();
        $openMultiplier = null;

        foreach ($passengers as $i => $passenger) {
            $type = $passenger['passenger_type'] ?? 'adult';
            $fare = $route->fareFor($boardingSeq, $dropoffSeq, $type);
            $base = $fare ? (float) $fare->amount : (float) $trip->base_fare;

            $modifier = (float) ($seatList[$i]?->price_modifier ?? 0);
            $seatModifiers += $modifier;
            $line = round($base + $modifier, 2);
            $subtotal += $line;

            $breakdown[] = [
                'index' => $i,
                'passenger_type' => $type,
                'seat' => $seatList[$i]?->label,
                'base' => $base,
                'seat_modifier' => $modifier,
                'amount' => $line,
            ];
        }

        return [
            'currency' => $currency,
            'subtotal' => round($subtotal, 2),
            'seat_modifiers' => round($seatModifiers, 2),
            'total_before_discount' => round($subtotal, 2),
            'breakdown' => $breakdown,
        ];
    }

    public function openFareMultiplier(object $tenant): float
    {
        return (float) $tenant->bookingRule('open_ticket_multiplier', 1.0);
    }
}
