<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            AuditLog::query()
                ->when($request->tenant_id, fn ($q, $v) => $q->where('tenant_id', $v))
                ->when($request->action, fn ($q, $v) => $q->where('action', 'like', "{$v}%"))
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 30))
        );
    }
}
