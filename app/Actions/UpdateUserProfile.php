<?php

namespace App\Actions;

use App\Jobs\SendProfileEmailVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUserProfile
{
    /** @param array{first_name: string, last_name: string, email: string, phone: string} $data */
    public function handle(User $user, array $data): bool
    {
        try {
            return DB::transaction(function () use ($user, $data): bool {
                $account = User::query()->lockForUpdate()->findOrFail($user->id);
                $account->fill($data);
                $emailChanged = $account->isDirty('email');

                if ($emailChanged) {
                    $account->email_verified_at = null;
                }

                $account->save();

                if ($emailChanged) {
                    SendProfileEmailVerification::dispatch($account->id, sha1($account->email));
                }

                return $emailChanged;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $errors = [];
            foreach (['email', 'phone'] as $field) {
                if (User::query()->whereKeyNot($user->id)->where($field, $data[$field])->exists()) {
                    $errors[$field] = __('validation.unique', ['attribute' => __('validation.attributes.'.$field)]);
                }
            }

            if ($errors === []) {
                throw $exception;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
