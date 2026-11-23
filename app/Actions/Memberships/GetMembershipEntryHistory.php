<?php

namespace App\Actions\Memberships;

use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GetMembershipEntryHistory
{
    /** @return LengthAwarePaginator<int, MembershipEntry> */
    public function handle(User $actor, int $membershipId, int $page = 1): LengthAwarePaginator
    {
        if (! $actor->exists) {
            throw new AuthorizationException;
        }
        $actor = User::query()->whereKey($actor->id)->first();
        if ($actor === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('viewEntries', Membership::class);
        if ($page < 1 || $page > intdiv(PHP_INT_MAX, 20)) {
            throw ValidationException::withMessages(['page' => 'Wybierz poprawną stronę historii.']);
        }
        $membership = Membership::query()->findOrFail($membershipId);

        return $membership->entries()->with('recordedBy:id,name')->orderByDesc('used_at')->orderByDesc('id')
            ->paginate(20, ['id', 'membership_id', 'recorded_by_user_id', 'used_at', 'created_at', 'request_id'], 'page', $page);
    }
}
