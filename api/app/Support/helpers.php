<?php

use App\Models\Tenant;
use App\Support\TenantContext;

if (! function_exists('tenant')) {
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}

if (! function_exists('tenant_id')) {
    function tenant_id(): ?int
    {
        return app(TenantContext::class)->id();
    }
}

if (! function_exists('tenant_cache_key')) {
    function tenant_cache_key(string $key): string
    {
        return 'tenant:'.(tenant_id() ?? 'platform').':'.$key;
    }
}
