<?php

namespace App\Policies;

use App\Models\BusRoute;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RoutePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'routes.view');
    }

    public function view(User $user, BusRoute $route): bool
    {
        return $this->owns($user, $route) && $this->allow($user, 'routes.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'routes.create');
    }

    public function update(User $user, BusRoute $route): bool
    {
        return $this->owns($user, $route) && $this->allow($user, 'routes.update');
    }

    public function delete(User $user, BusRoute $route): bool
    {
        return $this->owns($user, $route) && $this->allow($user, 'routes.delete');
    }
}
