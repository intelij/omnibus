<?php

namespace App\Jobs;

use App\Services\PrivacyService;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * PII retention sweep — anonymizes identifying fields on settled bookings
 * older than the tenant's pii_retention_days rule (default 730d).
 * Idempotent: pii_anonymized_at markers make re-runs no-ops.
 */
class AnonymizeOldBookingDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PrivacyService $privacy): void
    {
        $privacy->anonymizeExpired();
    }

    public function failed(?\Throwable $e): void
    {
        Audit::record('job.failed', null, [
            'job' => static::class,
            'error' => $e?->getMessage(),
        ]);
    }
}
