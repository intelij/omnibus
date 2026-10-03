<?php

namespace App\Console\Commands;

use App\Models\Bus;
use Illuminate\Console\Command;

/**
 * Repair buses with NULL/0 capacity or layouts with zero seats.
 * Never invents data — buses whose configuration can't be determined are
 * flagged for admin correction, not silently "fixed".
 */
class RepairBusSeatsCommand extends Command
{
    protected $signature = 'transithub:repair-bus-seats';

    protected $description = 'Repair buses missing capacity or seats where the layout can determine it';

    public function handle(): int
    {
        $repaired = $skipped = $flagged = 0;

        foreach (Bus::with('seatLayout.seats')->orderBy('id')->get() as $bus) {
            $layout = $bus->seatLayout;
            $seats = $layout?->seats->where('is_bookable', true)->count() ?? 0;

            if ($bus->capacity > 0 && $seats === $bus->capacity) {
                $skipped++;
                $this->line("  Bus #{$bus->id} {$bus->registration_number} — already correct ({$bus->capacity} seats)");

                continue;
            }

            if (! $layout) {
                $flagged++;
                $this->warn("  Bus #{$bus->id} {$bus->registration_number} — no seat layout; flag for admin configuration");

                continue;
            }

            if ($seats === 0) {
                $flagged++;
                $this->warn("  Bus #{$bus->id} {$bus->registration_number} — layout '{$layout->name}' has no seats; regenerate it in admin");

                continue;
            }

            $bus->capacity = $seats;
            $bus->save();
            $repaired++;
            $this->info("  Bus #{$bus->id} {$bus->registration_number} — capacity set to {$seats} seats");
        }

        $this->newLine();
        $this->info("Done: {$repaired} repaired, {$skipped} already correct, {$flagged} flagged for admin.");

        return $flagged > 0 ? self::FAILURE : self::SUCCESS;
    }
}
