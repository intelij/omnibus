<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BusPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'buses.view');
    }

    public function view(User $user, Bus $bus): bool
    {
        return $this->owns($user, $bus) && $this->allow($user, 'buses.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'buses.create');
    }

    public function update(User $user, Bus $bus): bool
    {
        return $this->owns($user, $bus) && $this->allow($user, 'buses.update');
    }

    public function delete(User $user, Bus $bus): bool
    {
        return $this->owns($user, $bus) && $this->allow($user, 'buses.delete');
    }
}
