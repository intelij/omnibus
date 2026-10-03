<?php

namespace App\Jobs;

use App\Services\PaymentService;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Catches missed provider webhooks: polls gateway status for payments still
 * open (Paynow pollurl and similar). Scheduled every few minutes; safe to
 * run concurrently with webhooks since both paths dedupe + verify amounts.
 */
class ReconcilePendingPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(PaymentService $payments): void
    {
        $payments->reconcilePending();
    }

    public function failed(?\Throwable $e): void
    {
        Audit::record('job.failed', null, [
            'job' => static::class,
            'error' => $e?->getMessage(),
        ]);
    }
}
