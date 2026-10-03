<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class TripPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'trips.view');
    }

    public function view(User $user, Trip $trip): bool
    {
        return $this->owns($user, $trip) && $this->allow($user, 'trips.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'schedules.create');
    }

    public function update(User $user, Trip $trip): bool
    {
        return $this->owns($user, $trip) && $this->allow($user, 'trips.update');
    }

    public function cancel(User $user, Trip $trip): bool
    {
        return $this->owns($user, $trip) && $this->allow($user, 'trips.cancel');
    }

    public function manifest(User $user, Trip $trip): bool
    {
        return $this->owns($user, $trip) && $this->allow($user, 'trips.manifest');
    }
}
