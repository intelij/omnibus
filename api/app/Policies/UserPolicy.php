<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class UserPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $this->owns($user, $model) && $this->allow($user, 'users.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $this->owns($user, $model) && $this->allow($user, 'users.update');
    }

    public function disable(User $user, User $model): bool
    {
        return $this->owns($user, $model) && $this->allow($user, 'users.disable');
    }
}
