<?php

namespace App\Policies;

use App\Models\SeatLayout;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class SeatLayoutPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'seat_layouts.view');
    }

    public function view(User $user, SeatLayout $layout): bool
    {
        return $this->owns($user, $layout) && $this->allow($user, 'seat_layouts.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'seat_layouts.create');
    }

    public function update(User $user, SeatLayout $layout): bool
    {
        return $this->owns($user, $layout) && $this->allow($user, 'seat_layouts.update');
    }

    public function delete(User $user, SeatLayout $layout): bool
    {
        return $this->owns($user, $layout) && $this->allow($user, 'seat_layouts.delete');
    }
}
