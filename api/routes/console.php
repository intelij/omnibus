<?php

use App\Jobs\AnonymizeOldBookingDataJob;
use App\Jobs\ExpireStalePaymentsJob;
use App\Jobs\GenerateTripsJob;
use App\Jobs\ReconcilePendingPaymentsJob;
use App\Jobs\ReleaseExpiredSeatHoldsJob;
use App\Jobs\TransitionTripStatusesJob;
use Illuminate\Support\Facades\Schedule;

// All scheduled work is idempotent — safe to run on every node.
Schedule::job(new ReleaseExpiredSeatHoldsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new ExpireStalePaymentsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new ReconcilePendingPaymentsJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new TransitionTripStatusesJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new GenerateTripsJob)->daily()->withoutOverlapping();
Schedule::job(new AnonymizeOldBookingDataJob)->daily()->withoutOverlapping();
