<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class MembershipPlanPolicy
{
    public function manage(User $actor): bool
    {
        return ! $actor->isSuspended() && $actor->hasVerifiedEmail() && $actor->role === UserRole::Admin;
    }
}
