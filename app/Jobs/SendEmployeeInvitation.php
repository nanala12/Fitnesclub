<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;
use RuntimeException;

class SendEmployeeInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [65, 180, 300];

    public function __construct(public int $userId, public string $emailHash)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user === null || ! $user->hasPendingStaffInvitation() || $user->isSuspended()
            || ! in_array($user->role, [UserRole::Employee, UserRole::Admin], true)
            || ! hash_equals($this->emailHash, sha1($user->email))) {
            return;
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email], function (User $recipient, string $token): void {
            $recipient->notify(new EmployeeInvitation($token));
        });
        if ($status === Password::ResetThrottled) {
            $this->release(65);
        } elseif ($status !== Password::ResetLinkSent) {
            throw new RuntimeException('Employee invitation delivery could not be scheduled.');
        }
    }
}
