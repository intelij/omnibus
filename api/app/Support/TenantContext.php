<?php

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the tenant resolved for the current request/job. Bound as a
 * singleton; populated by ResolveTenant middleware (HTTP) or
 * TenantAwareJob (queues). All tenant-scoped queries read from here.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        if ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        }
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('No tenant context bound.');
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
