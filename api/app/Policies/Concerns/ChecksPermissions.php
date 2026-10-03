<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksPermissions
{
    /** Platform admins pass; others must hold the permission in-tenant. */
    protected function allow(User $user, string $permission): bool
    {
        if ($user->is_platform_user) {
            return $user->hasRole('platform_super_admin');
        }

        return $user->hasPermissionTo($permission, 'sanctum');
    }

    protected function owns(User $user, object $model): bool
    {
        return $user->is_platform_user || (isset($model->tenant_id) && $model->tenant_id === $user->tenant_id);
    }
}
