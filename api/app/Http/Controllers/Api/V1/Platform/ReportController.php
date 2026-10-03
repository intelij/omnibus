<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** Platform-wide aggregates — the only place cross-tenant reads happen. */
    public function overview()
    {
        return response()->json([
            'tenants' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
            ],
            'bookings_30d' => Booking::withoutTenantScope()->where('created_at', '>=', now()->subDays(30))->count(),
            'revenue_30d' => Payment::withoutTenantScope()->where('status', 'paid')
                ->where('confirmed_at', '>=', now()->subDays(30))
                ->select('currency', DB::raw('sum(amount) as total'))
                ->groupBy('currency')->get(),
        ]);
    }
}
