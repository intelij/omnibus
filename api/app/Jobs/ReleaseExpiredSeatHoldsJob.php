<?php

namespace App\Jobs;

use App\Services\BookingService;
use App\Services\SeatInventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sweeps expired seat holds — idempotent by design (state + time check). */
class ReleaseExpiredSeatHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(SeatInventoryService $seats, BookingService $bookings): void
    {
        $seats->releaseExpiredHolds();
        $bookings->expireStaleBookings();
    }
}
