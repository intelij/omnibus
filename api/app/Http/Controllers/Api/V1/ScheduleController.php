<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Models\Schedule;
use App\Services\TripService;
use App\Support\Audit;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(private TripService $trips) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Schedule::class);

        return response()->json(
            Schedule::with(['route:id,name,origin,destination', 'bus:id,registration_number'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('departure_time')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(StoreScheduleRequest $request)
    {
        $this->authorize('create', Schedule::class);

        $data = $request->validated();
        $schedule = Schedule::create(collect($data)->except('generate_days')->all());

        if ($days = $data['generate_days'] ?? null) {
            $this->trips->generateFromSchedule($schedule, now(), now()->addDays($days));
        }

        Audit::record('schedule.created', $schedule);

        return response()->json($schedule->load(['route', 'bus']), 201);
    }

    public function show(Schedule $schedule)
    {
        $this->authorize('view', $schedule);

        return response()->json($schedule->load(['route.stops', 'bus.seatLayout']));
    }

    public function update(StoreScheduleRequest $request, Schedule $schedule)
    {
        $this->authorize('update', $schedule);

        $schedule->update(collect($request->validated())->except('generate_days')->all());
        Audit::record('schedule.updated', $schedule);

        return response()->json($schedule);
    }

    public function destroy(Schedule $schedule)
    {
        $this->authorize('delete', $schedule);
        $schedule->update(['status' => 'paused']);
        Audit::record('schedule.paused', $schedule);

        return response()->json(['message' => 'Schedule paused.']);
    }

    public function generateTrips(Schedule $schedule, Request $request)
    {
        $this->authorize('update', $schedule);

        $days = min(90, max(1, $request->integer('days', 30)));
        $created = $this->trips->generateFromSchedule($schedule, now(), now()->addDays($days));

        Audit::record('schedule.trips_generated', $schedule, ['created' => $created]);

        return response()->json(['created' => $created]);
    }
}
