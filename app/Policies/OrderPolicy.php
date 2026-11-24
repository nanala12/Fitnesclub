<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class OrderPolicy
{
    public function createReception(User $actor): bool
    {
        return ! $actor->isSuspended() && $actor->hasVerifiedEmail()
            && in_array($actor->role, [UserRole::Admin, UserRole::Employee], true);
    }

    public function confirmReception(User $actor): bool
    {
        return $this->createReception($actor);
    }
}
