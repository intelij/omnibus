<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeatLayoutRequest;
use App\Models\SeatLayout;
use App\Models\SeatLayoutSeat;
use App\Services\SeatLayoutGenerator;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeatLayoutController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SeatLayout::class);

        return response()->json(
            SeatLayout::withCount('seats')->orderBy('name')->paginate($request->integer('per_page', 15))
        );
    }

    public function store(StoreSeatLayoutRequest $request)
    {
        $this->authorize('create', SeatLayout::class);

        $data = $request->validated();

        $layout = DB::transaction(function () use ($data) {
            $layout = SeatLayout::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'decks' => $data['decks'],
                'rows' => $data['rows'],
                'cols' => $data['cols'],
                'status' => 'draft',
            ]);

            $this->persistSeats($layout, $data['seats']);

            return $layout;
        });

        Audit::record('seat_layout.created', $layout);

        return response()->json($layout->load('seats'), 201);
    }

    public function show(SeatLayout $seatLayout)
    {
        $this->authorize('view', $seatLayout);

        return response()->json($seatLayout->load('seats'));
    }

    public function update(StoreSeatLayoutRequest $request, SeatLayout $seatLayout)
    {
        $this->authorize('update', $seatLayout);

        $data = $request->validated();

        DB::transaction(function () use ($seatLayout, $data) {
            $seatLayout->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'decks' => $data['decks'],
                'rows' => $data['rows'],
                'cols' => $data['cols'],
            ]);
            $seatLayout->seats()->delete();
            $this->persistSeats($seatLayout, $data['seats']);
        });

        Audit::record('seat_layout.updated', $seatLayout);

        return response()->json($seatLayout->load('seats'));
    }

    /**
     * Generate seats from a ZW-style bus configuration (2+2 + rear row,
     * 2+1, 1+1, 3+2 …). Draft layouts only — published layouts are frozen.
     */
    public function generate(Request $request, SeatLayout $seatLayout)
    {
        $this->authorize('update', $seatLayout);

        $data = $request->validate([
            'rows' => ['required', 'integer', 'min:1', 'max:40'],
            'left_seats' => ['required', 'integer', 'min:0', 'max:4'],
            'right_seats' => ['required', 'integer', 'min:0', 'max:4'],
            'rear_row_seats' => ['nullable', 'integer', 'min:0', 'max:10'],
            'deck' => ['nullable', 'integer', 'min:1', 'max:3'],
            'label_style' => ['nullable', 'in:row_letter,numeric'],
        ]);

        $layout = app(SeatLayoutGenerator::class)->generate($seatLayout, $data);

        Audit::record('seat_layout.generated', $layout, [
            'rows' => $data['rows'], 'left' => $data['left_seats'],
            'right' => $data['right_seats'], 'rear' => $data['rear_row_seats'] ?? 0,
            'capacity' => $layout->seats->count(),
        ]);

        return response()->json($layout);
    }

    /**
     * Clone a layout — the versioning path for reusing configurations.
     * Templates are layouts; buses bind to whichever layout is current,
     * and historical trips keep their materialised seat snapshot.
     */
    public function duplicate(SeatLayout $seatLayout)
    {
        $this->authorize('create', SeatLayout::class);

        $copy = DB::transaction(function () use ($seatLayout) {
            $copy = $seatLayout->replicate(['status']);
            $copy->name = $seatLayout->name.' (copy)';
            $copy->status = 'draft';
            $copy->save();

            foreach ($seatLayout->seats as $seat) {
                $s = $seat->replicate(['seat_layout_id']);
                $s->seat_layout_id = $copy->id;
                $s->save();
            }

            return $copy;
        });

        Audit::record('seat_layout.duplicated', $copy);

        return response()->json($copy->load('seats'), 201);
    }

    public function destroy(SeatLayout $seatLayout)
    {
        $this->authorize('delete', $seatLayout);

        if ($seatLayout->buses()->exists()) {
            return response()->json(['message' => 'Layout is in use by a bus — archive it instead.'], 422);
        }

        $seatLayout->delete();
        Audit::record('seat_layout.deleted', $seatLayout);

        return response()->json(['message' => 'Layout deleted.']);
    }

    public function publish(SeatLayout $seatLayout)
    {
        $this->authorize('update', $seatLayout);

        $bookable = $seatLayout->seats()->where('is_bookable', true)->count();
        if ($bookable === 0) {
            return response()->json(['message' => 'A layout needs at least one bookable seat.'], 422);
        }

        $seatLayout->update(['status' => 'published']);
        Audit::record('seat_layout.published', $seatLayout);

        return response()->json($seatLayout);
    }

    private function persistSeats(SeatLayout $layout, array $seats): void
    {
        // Configuration integrity: every seat must sit inside the declared
        // grid — a partially-generated layout can't be saved.
        $bad = collect($seats)->first(fn ($s) => $s['row'] > $layout->rows || $s['col'] > $layout->cols);
        if ($bad) {
            throw ValidationException::withMessages([
                'seats' => "Seat at row {$bad['row']}, col {$bad['col']} is outside the {$layout->rows}×{$layout->cols} grid.",
            ]);
        }

        $rows = collect($seats)->map(function ($seat, $i) use ($layout) {
            return [
                'tenant_id' => $layout->tenant_id,
                'seat_layout_id' => $layout->id,
                'deck' => $seat['deck'],
                'row' => $seat['row'],
                'col' => $seat['col'],
                'label' => $seat['label'],
                'seat_number' => $seat['seat_number'] ?? null,
                'type' => $seat['type'],
                'position' => $seat['position'] ?? 'none',
                'side' => $seat['side'] ?? null,
                'price_modifier' => $seat['price_modifier'] ?? 0,
                'is_bookable' => ! in_array($seat['type'], SeatLayoutSeat::NON_BOOKABLE_TYPES, true),
                'attributes' => isset($seat['attributes']) ? json_encode($seat['attributes']) : null,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        SeatLayoutSeat::insert($rows);
    }
}
