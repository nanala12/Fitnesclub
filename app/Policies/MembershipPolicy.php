<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class MembershipPolicy
{
    public function renew(User $actor): bool
    {
        return $this->issue($actor);
    }

    public function redeem(User $actor): bool
    {
        return $this->issue($actor);
    }

    public function viewEntries(User $actor): bool
    {
        return $this->issue($actor);
    }

    public function activate(User $actor): bool
    {
        return $this->issue($actor);
    }

    public function issue(User $actor): bool
    {
        return ! $actor->isSuspended() && $actor->hasVerifiedEmail()
            && in_array($actor->role, [UserRole::Admin, UserRole::Employee], true);
    }
}
