<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $actor, Order $order): bool
    {
        return ! $actor->isSuspended() && $order->purchaser_user_id === $actor->id;
    }

    public function initiateOnlinePayment(User $actor, Order $order): bool
    {
        return ! $actor->isSuspended() && $order->purchaser_user_id === $actor->id;
    }

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
