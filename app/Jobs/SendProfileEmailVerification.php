<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProfileEmailVerification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $userId, public string $emailHash)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null || $user->isSuspended() || $user->hasVerifiedEmail() || ! hash_equals($this->emailHash, sha1($user->email))) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}
