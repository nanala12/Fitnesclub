<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actor, User $user): bool
    {
        return ! $actor->isSuspended() && $actor->is($user);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->view($actor, $user);
    }
}
