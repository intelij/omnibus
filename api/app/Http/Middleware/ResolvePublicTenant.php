<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public booking API: resolves the operator from the request host —
 * {slug}.{base_domain} or a configured custom_domain. A
 * ?tenant= fallback is allowed only in local/testing for dev convenience
 * (never trust it in production).
 */
class ResolvePublicTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveFromHost($request->getHost())
            ?? $this->resolveFromParam($request);

        if ($tenant === null || ! $tenant->isActive()) {
            return response()->json(['message' => 'Booking service is unavailable.'], 404);
        }

        $this->context->set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    private function resolveFromHost(string $host): ?Tenant
    {
        $base = config('transithub.base_domain');

        if ($host !== $base && str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));

            return Tenant::where('slug', $slug)->first();
        }

        return Tenant::where('custom_domain', $host)->first();
    }

    private function resolveFromParam(Request $request): ?Tenant
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        $slug = $request->query('tenant') ?? $request->header('X-Tenant');

        return $slug ? Tenant::where('slug', $slug)->first() : null;
    }
}
