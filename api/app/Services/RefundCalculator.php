<?php

namespace App\Services;

use App\Models\Booking;

/**
 * Tenant-configured cancellation windows. Never hard-codes business rules —
 * reads tenant_booking_rules.cancellation_policy tiers.
 */
class RefundCalculator
{
    public function forCancellation(Booking $booking): float
    {
        $paid = $booking->paidPayment;
        if (! $paid) {
            return 0.0;
        }

        $hours = now()->diffInHours($booking->trip->departure_at, false);
        if ($hours < 0) {
            return 0.0; // departure passed
        }

        $percent = 0;
        foreach ($this->policy($booking) as $window) {
            if ($hours >= (int) $window['min_hours_before']) {
                $percent = (int) $window['percent'];
                break;
            }
        }

        return round((float) $booking->total * ($percent / 100), 2);
    }

    private function policy(Booking $booking): array
    {
        $policy = $booking->tenant->bookingRule('cancellation_policy', []);
        usort($policy, fn ($a, $b) => $b['min_hours_before'] <=> $a['min_hours_before']);

        return $policy ?: [['min_hours_before' => 0, 'percent' => 0]];
    }
}
