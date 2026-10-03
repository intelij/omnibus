<?php

namespace App\Jobs;

use App\Models\Trip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Time-based trip status transitions — idempotent (checks before update). */
class TransitionTripStatusesJob implements ShouldQueue
{
    use Queueable;

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
}
