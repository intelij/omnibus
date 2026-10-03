<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BookingPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'bookings.view');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) && $this->allow($user, 'bookings.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'bookings.create');
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) && $this->allow($user, 'bookings.update');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) && $this->allow($user, 'bookings.cancel');
    }

    public function refund(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) && $this->allow($user, 'bookings.refund');
    }

    public function resendTicket(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking) && $this->allow($user, 'tickets.resend');
    }
}
