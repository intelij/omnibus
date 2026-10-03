<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For authenticated staff/agent routes: the tenant comes ONLY from the
 * authenticated user — never from request input. Platform users carry no
 * tenant; their cross-tenant access is explicit in platform controllers.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Account is disabled.'], 403);
        }

        if ($user->is_platform_user) {
            return $next($request); // platform routes handle their own scoping
        }

        $tenant = $user->tenant;
        if ($tenant === null) {
            return response()->json(['message' => 'No tenant associated with this account.'], 403);
        }

        if (! $tenant->isActive()) {
            return response()->json(['message' => 'This operator account is not active.'], 403);
        }

        $this->context->set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
