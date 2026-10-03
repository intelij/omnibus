<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class TicketPolicy
{
    use ChecksPermissions;

    public function verify(User $user): bool
    {
        return $this->allow($user, 'tickets.verify');
    }

    public function resend(User $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket) && $this->allow($user, 'tickets.resend');
    }
}
