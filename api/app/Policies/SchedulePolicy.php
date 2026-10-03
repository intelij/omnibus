<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class SchedulePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'schedules.view');
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return $this->owns($user, $schedule) && $this->allow($user, 'schedules.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'schedules.create');
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $this->owns($user, $schedule) && $this->allow($user, 'schedules.update');
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $this->owns($user, $schedule) && $this->allow($user, 'schedules.update');
    }
}
