<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusRequest;
use App\Models\Bus;
use App\Models\SeatLayout;
use App\Services\SeatLayoutGenerator;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BusController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Bus::class);

        $query = Bus::with(['seatLayout' => fn ($q) => $q->select('id', 'name', 'rows', 'cols', 'status')->withCount('seats')])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $v) => $q->where(fn ($q2) => $q2
                ->where('registration_number', 'like', "%{$v}%")
                ->orWhere('fleet_number', 'like', "%{$v}%")))
            ->orderBy('registration_number');

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreBusRequest $request)
    {
        $this->authorize('create', Bus::class);

        try {
            $bus = DB::transaction(function () use ($request) {
                $bus = Bus::create(Arr::except($request->validated(), 'seating_config'));
                $this->bindSeating($bus, $request->validated());

                return $bus;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('bus.create_failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'seating_config' => 'Unable to save the bus. Please configure the seating layout before saving.',
            ]);
        }

        Audit::record('bus.created', $bus);

        return response()->json($bus->load('seatLayout'), 201);
    }

    public function show(Bus $bus)
    {
        $this->authorize('view', $bus);

        return response()->json($bus->load('seatLayout.seats'));
    }

    public function update(StoreBusRequest $request, Bus $bus)
    {
        $this->authorize('update', $bus);

        try {
            DB::transaction(function () use ($request, $bus) {
                $bus->update(Arr::except($request->validated(), 'seating_config'));
                $this->bindSeating($bus, $request->validated());
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('bus.update_failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'seating_config' => 'Unable to save the bus. Please configure the seating layout before saving.',
            ]);
        }

        Audit::record('bus.updated', $bus);

        return response()->json($bus->fresh('seatLayout'));
    }

    /**
     * Attach seating to a bus: generate a layout from seating_config (a new
     * layout = the versioning path — existing trips keep their materialised
     * snapshot) or bind an existing layout; then derive capacity server-side.
     */
    private function bindSeating(Bus $bus, array $data): void
    {
        if ($cfg = ($data['seating_config'] ?? null)) {
            $layout = SeatLayout::create([
                'name' => $cfg['name'] ?? "{$bus->registration_number} seating",
                'decks' => 1, 'rows' => 0, 'cols' => 0,
                'status' => 'draft',
            ]);
            app(SeatLayoutGenerator::class)->generate($layout, $cfg);
            $layout->update(['status' => 'published']);
            $bus->seat_layout_id = $layout->id;
        } elseif (array_key_exists('seat_layout_id', $data)) {
            $bus->seat_layout_id = $data['seat_layout_id'];
        }

        $layout = $bus->seatLayout;
        abort_if(! $layout, 422, 'Please configure the seating layout before saving the bus.');

        $capacity = $layout->seats()->where('is_bookable', true)->count();
        abort_if($capacity === 0, 422, "The '{$layout->name}' layout has no bookable seats.");

        // capacity is ALWAYS derived from the layout — never client-supplied.
        $bus->capacity = $capacity;
        $bus->save();
    }

    public function destroy(Bus $bus)
    {
        $this->authorize('delete', $bus);

        if ($bus->trips()->where('departure_at', '>', now())->exists()) {
            return response()->json(['message' => 'This bus has upcoming trips — retire it instead.'], 422);
        }

        $bus->delete();
        Audit::record('bus.deleted', $bus);

        return response()->json(['message' => 'Bus removed.']);
    }
}
