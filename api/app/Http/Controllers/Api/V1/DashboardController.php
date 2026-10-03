<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, ReportService $reports)
    {
        $user = $request->user();
        $data = ['role' => $user->getRoleNames()->first(), 'widgets' => []];

        // Role-aware dashboards — each role sees only what it needs (§41).
        if ($user->can('reports.view') || $user->can('bookings.view')) {
            $data['widgets']['operations'] = $reports->dashboard();
        }
        if ($user->can('payments.view')) {
            $data['widgets']['finance'] = $reports->paymentFailures(now()->subDays(30), now());
        }
        if ($user->can('support.view')) {
            $data['widgets']['support'] = [
                'open_tickets' => \App\Models\SupportTicket::where('status', 'open')->count(),
            ];
        }

        return response()->json($data);
    }
}
