<?php

namespace App\Jobs;

use App\Models\Schedule;
use App\Models\Tenant;
use App\Services\TripService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Nightly: materialise trips from active schedules for the next N days. */
class GenerateTripsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $horizonDays = 30) {}

    public function handle(TripService $trips, TenantContext $context): void
    {
        Tenant::where('status', Tenant::STATUS_ACTIVE)->each(function (Tenant $tenant) use ($trips, $context) {
            $context->set($tenant);

            Schedule::where('status', 'active')
                ->with('route')
                ->each(fn (Schedule $schedule) => $trips->generateFromSchedule($schedule, now(), now()->addDays($this->horizonDays)));

            $context->clear();
        });
    }
}
