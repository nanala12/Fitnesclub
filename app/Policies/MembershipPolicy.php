<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class MembershipPolicy
{
    public function issue(User $actor): bool
    {
        return ! $actor->isSuspended() && $actor->hasVerifiedEmail()
            && in_array($actor->role, [UserRole::Admin, UserRole::Employee], true);
    }
}
