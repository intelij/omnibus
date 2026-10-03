<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Time-based trip status transitions — idempotent (checks before update). */
class TransitionTripStatusesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        Trip::withoutTenantScope()
            ->where('status', Trip::STATUS_SCHEDULED)
            ->where('departure_at', '<=', now()->subMinutes(30))
            ->update(['status' => Trip::STATUS_DEPARTED]);

        Trip::withoutTenantScope()
            ->where('status', Trip::STATUS_DEPARTED)
            ->whereNotNull('arrival_estimate_at')
            ->where('arrival_estimate_at', '<=', now())
            ->update(['status' => Trip::STATUS_ARRIVED]);
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
