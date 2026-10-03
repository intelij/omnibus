<?php

namespace App\Console\Commands;

use App\Models\Bus;
use Illuminate\Console\Command;

/** Diagnostic: every bus must have capacity>0, a layout, and matching seats. */
class VerifyBusesCommand extends Command
{
    protected $signature = 'transithub:verify-buses';

    protected $description = 'Verify every bus has a seating layout, capacity and matching seat count';

    public function handle(): int
    {
        $this->info('TransitHub Bus Verification');
        $this->newLine();

        $ok = true;
        foreach (Bus::with('seatLayout.seats')->orderBy('id')->get() as $bus) {
            $seats = $bus->seatLayout?->seats->where('is_bookable', true)->count() ?? 0;
            $good = $bus->capacity > 0 && $seats === $bus->capacity;
            $ok = $ok && $good;

            $this->line(($good ? '  <info>✓</info>' : '  <error>✗</error>')
                ." Bus #{$bus->id} — {$bus->registration_number} ({$bus->tenant->trading_name})");
            $this->line("    Capacity: {$bus->capacity}".($good ? '' : '  ← mismatch'));
            $this->line('    Seats: '.$seats.($bus->seatLayout ? " ({$bus->seatLayout->name})" : ' ← no layout'));
        }

        $this->newLine();
        $ok ? $this->info('All buses verified successfully.') : $this->error('Some buses need repair — run php artisan transithub:repair-bus-seats');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
