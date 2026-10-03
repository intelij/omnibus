<?php

namespace App\Jobs;

use App\Services\BookingService;
use App\Services\SeatInventoryService;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sweeps expired seat holds — idempotent by design (state + time check). */
class ReleaseExpiredSeatHoldsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SeatInventoryService $seats, BookingService $bookings): void
    {
        $seats->releaseExpiredHolds();
        $bookings->expireStaleBookings();
    }

    /**
     * Final-failure alert — job exhausted retries. Operators get the
     * failed_jobs table row plus this audit entry so the gap is visible.
     */
    public function failed(?\Throwable $e): void
    {
        Audit::record('job.failed', null, [
            'job' => static::class,
            'error' => $e?->getMessage(),
        ]);
    }
}
