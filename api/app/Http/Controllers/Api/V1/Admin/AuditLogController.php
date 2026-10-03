<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('audit.view'), 403);

        return response()->json(
            AuditLog::where('tenant_id', tenant_id())
                ->when($request->action, fn ($q, $v) => $q->where('action', 'like', "{$v}%"))
                ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 30))
        );
    }
}
