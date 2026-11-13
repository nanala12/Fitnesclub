<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
            ...array_fill_keys(['role', 'is_active', 'suspended_at', 'email_verified_at', 'bootstrap_key', 'staff_invited_at', 'staff_invitation_accepted_at', 'permissions'], ['prohibited']),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
                ->where('email', $account->email)->lockForUpdate()->first();
            if (! Password::broker()->tokenExists($account, $input['token']) || ($account->hasPendingStaffInvitation() && $account->isSuspended())) {
                throw ValidationException::withMessages(['email' => __('passwords.token')]);
            }
            $account->password = $input['password'];
            if ($account->hasPendingStaffInvitation()) {
                $account->staff_invitation_accepted_at = Carbon::now();
                $account->email_verified_at = Carbon::now();
            }
            $account->save();
            Password::broker()->deleteToken($account);
            // Fortify saves this model again to rotate its remember token after the callback.
            $user->setRawAttributes($account->getAttributes(), sync: true);
        }, attempts: 3);
    }
}
