<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class SupportTicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'support.view');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $this->allow($user, 'support.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'support.create');
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $this->allow($user, 'support.update');
    }

    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $this->allow($user, 'support.assign');
    }

    public function close(User $user, SupportTicket $ticket): bool
    {
        return $this->owns($user, $ticket) && $this->allow($user, 'support.close');
    }
}
