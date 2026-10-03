<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    private function window(Request $request): array
    {
        return [
            Carbon::parse($request->query('date_from', now()->subDays(30)->toDateString())),
            Carbon::parse($request->query('date_to', now()->toDateString()))->endOfDay(),
        ];
    }

    public function revenue(Request $request)
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->window($request);

        return response()->json($this->reports->revenue($from, $to));
    }

    public function occupancy(Request $request)
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->window($request);

        return response()->json($this->reports->occupancy($from, $to));
    }

    public function agents(Request $request)
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->window($request);

        return response()->json($this->reports->agentPerformance($from, $to));
    }

    public function payments(Request $request)
    {
        abort_unless($request->user()->can('payments.view'), 403);
        [$from, $to] = $this->window($request);

        return response()->json($this->reports->paymentFailures($from, $to));
    }
}
