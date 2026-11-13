<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function createEmployee(User $actor): bool
    {
        return ! $actor->isSuspended() && $actor->hasVerifiedEmail() && $actor->role === UserRole::Admin;
    }

    public function manageEmployee(User $actor, User $employee): bool
    {
        return $this->createEmployee($actor) && ! $actor->is($employee)
            && in_array($employee->role, [UserRole::Employee, UserRole::Admin], true);
    }

    public function view(User $actor, User $user): bool
    {
        return ! $actor->isSuspended() && $actor->is($user);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->view($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $this->view($actor, $user) && $user->role === UserRole::Customer;
    }
}
