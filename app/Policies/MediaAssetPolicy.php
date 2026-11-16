<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\User;

class MediaAssetPolicy
{
    public function manage(User $user): bool
    {
        return ! $user->isSuspended() && $user->hasVerifiedEmail() && $user->role === UserRole::Admin;
    }

    public function view(User $user, MediaAsset $mediaAsset): bool
    {
        return $this->manage($user);
    }
}
