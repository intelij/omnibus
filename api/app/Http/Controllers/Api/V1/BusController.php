<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusRequest;
use App\Models\Bus;
use App\Support\Audit;
use Illuminate\Http\Request;

class BusController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Bus::class);

        $query = Bus::with('seatLayout:id,name')
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

        $bus = Bus::create($request->validated());
        $bus->update(['capacity' => $bus->seatLayout?->seats()->where('is_bookable', true)->count() ?? $bus->capacity]);

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

        $bus->update($request->validated());
        Audit::record('bus.updated', $bus);

        return response()->json($bus);
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
