<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        if (isset($input['phone']) && is_string($input['phone'])) {
            $input['phone'] = PhoneNumber::normalize($input['phone']);
        }

        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
            'suspended_at' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'id' => ['prohibited'],
            'bootstrap_key' => ['prohibited'],
        ])->validate();

        try {
            return User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => $validated['password'],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $errors = [];
            foreach (['email', 'phone'] as $field) {
                if (User::where($field, $validated[$field])->exists()) {
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
