<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PromotionPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allow($user, 'promotions.view');
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion) && $this->allow($user, 'promotions.view');
    }

    public function create(User $user): bool
    {
        return $this->allow($user, 'promotions.create');
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion) && $this->allow($user, 'promotions.update');
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $this->owns($user, $promotion) && $this->allow($user, 'promotions.delete');
    }
}
