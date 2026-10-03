<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PaymentPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->owns($user, $payment) && $this->allow($user, 'payments.view');
    }

    public function confirmCash(User $user, Payment $payment): bool
    {
        return $this->owns($user, $payment) && $this->allow($user, 'payments.confirm_cash');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->owns($user, $payment) && $this->allow($user, 'payments.refund');
    }

    public function reconcile(User $user): bool
    {
        return $this->allow($user, 'payments.reconcile');
    }

    public function export(User $user): bool
    {
        return $this->allow($user, 'payments.export');
    }
}
