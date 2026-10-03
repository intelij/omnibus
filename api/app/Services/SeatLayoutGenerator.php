<?php

namespace App\Services;

use App\Models\SeatLayout;
use App\Models\SeatLayoutSeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generates a data-driven seat layout from a ZW-style bus configuration:
 *
 *   {rows, left_seats, right_seats, rear_row_seats}
 *
 *   2+2  →  11 rows of [AB | CD] + one rear row of [ABCDE] = 49 seats
 *   2+1  →  left 2, aisle, right 1; rear row fills all columns
 *
 * Labels are stable: {row}{column-letter} (1A 1B | 1C 1D … 12A–12E).
 * Generation is only allowed on draft layouts — published layouts feeding
 * live trips are immutable (trip_seats already snapshot anyway).
 */
class SeatLayoutGenerator
{
    /**
     * @param  array{rows:int,left_seats:int,right_seats:int,rear_row_seats:int,deck?:int}  $cfg
     */
    public function generate(SeatLayout $layout, array $cfg): SeatLayout
    {
        if ($layout->status !== 'draft') {
            throw ValidationException::withMessages([
                'layout' => 'Only draft layouts can be regenerated. Publish creates an immutable version for trips.',
            ]);
        }

        $rows = (int) $cfg['rows'];
        $left = (int) $cfg['left_seats'];
        $right = (int) $cfg['right_seats'];
        $rear = (int) ($cfg['rear_row_seats'] ?? 0);
        $deck = (int) ($cfg['deck'] ?? 1);

        if ($rows < 1 || $left < 0 || $right < 0 || ($left + $right) < 1 || $rear < 0) {
            throw ValidationException::withMessages(['config' => 'Invalid seating configuration.']);
        }

        $labelStyle = $cfg['label_style'] ?? 'row_letter'; // row_letter | numeric
        $aisleCol = $left > 0 && $right > 0 ? $left + 1 : 0; // 0 = no aisle gap
        $cols = $left + $right + ($aisleCol ? 1 : 0);
        $totalRows = $rows + ($rear > 0 ? 1 : 0);
        $rearCols = $rear > 0 ? $cols : 0;

        // Seat letters run across all columns (aisle column included) so
        // section 2 continues the alphabet — A B | C D.
        $seatCols = [];
        for ($c = 1; $c <= $cols; $c++) {
            if ($c !== $aisleCol) {
                $seatCols[] = $c;
            }
        }

        $label = fn (int $row, int $col, int $n) => $labelStyle === 'numeric'
            ? (string) $n
            : $row.chr(64 + $col);

        $cells = [];
        $n = 0;
        for ($r = 1; $r <= $rows; $r++) {
            foreach ($seatCols as $c) {
                $n++;
                $cells[] = [
                    'deck' => $deck, 'row' => $r, 'col' => $c,
                    'label' => $label($r, $c, $n),
                    'type' => 'standard',
                    'position' => $this->position($c, $cols, $aisleCol),
                    'side' => $aisleCol ? ($c < $aisleCol ? 'left' : 'right') : 'center',
                    'seat_number' => $n,
                    'sort_order' => $n,
                ];
            }
        }

        if ($rear > 0) {
            for ($c = 1; $c <= $rearCols && $c <= $cols; $c++) {
                $n++;
                $cells[] = [
                    'deck' => $deck, 'row' => $rows + 1, 'col' => $c,
                    'label' => $label($rows + 1, $c, $n),
                    'type' => 'standard',
                    'position' => $this->position($c, $cols, 0),
                    'side' => $c === 1 || $c === $cols ? ($c === 1 ? 'left' : 'right') : 'center',
                    'seat_number' => $n,
                    'sort_order' => $n,
                ];
            }
            // rear_row_seats may exceed column count — extra seats appended as
            // additional columns so no seat is silently lost.
            for ($c = $cols + 1; $c <= $rear; $c++) {
                $n++;
                $cells[] = [
                    'deck' => $deck, 'row' => $rows + 1, 'col' => $c,
                    'label' => $label($rows + 1, $c, $n),
                    'type' => 'standard',
                    'position' => 'middle',
                    'side' => 'center',
                    'seat_number' => $n,
                    'sort_order' => $n,
                ];
            }
            $cols = max($cols, $rear);
        }

        DB::transaction(function () use ($layout, $cells, $totalRows, $cols) {
            $layout->seats()->delete();
            foreach ($cells as $cell) {
                SeatLayoutSeat::create($cell + [
                    'tenant_id' => $layout->tenant_id,
                    'seat_layout_id' => $layout->id,
                ]);
            }
            $layout->update([
                'rows' => $totalRows,
                'cols' => $cols,
            ]);
        });

        return $layout->fresh('seats');
    }

    private function position(int $col, int $cols, int $aisleCol): string
    {
        if ($col === 1 || $col === $cols) {
            return 'window';
        }
        if ($aisleCol && abs($col - $aisleCol) === 1) {
            return 'aisle';
        }

        return 'middle';
    }
}
