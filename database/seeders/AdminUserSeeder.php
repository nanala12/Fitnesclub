<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    private const string BOOTSTRAP_KEY = 'initial-admin';

    public function run(): void
    {
        $email = config('bootstrap.admin.email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : null;

        if (Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->fails()) {
            throw new RuntimeException('Set a valid BOOTSTRAP_ADMIN_EMAIL in secure configuration.');
        }

        try {
            DB::transaction(function () use ($email): void {
                $existing = User::query()->where('bootstrap_key', self::BOOTSTRAP_KEY)->lockForUpdate()->first();

                if ($existing !== null) {
                    if ($existing->email !== $email || $existing->role !== UserRole::Admin) {
                        throw new RuntimeException('Bootstrap identity has changed. Manual authorized review is required.');
                    }

                    return;
                }

                $phone = config('bootstrap.admin.phone');
                $data = [
                    'name' => config('bootstrap.admin.name'),
                    'email' => $email,
                    'phone' => is_string($phone) ? PhoneNumber::normalize($phone) : null,
                    'password' => config('bootstrap.admin.password'),
                ];

                $validator = Validator::make($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email', 'max:255'],
                    'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
                    'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
                ]);

                if ($validator->fails()) {
                    // Never attach input values or validation data to an exception or log.
                    throw new RuntimeException('Invalid bootstrap configuration fields: '.implode(', ', $validator->errors()->keys()).'. Password requires at least 12 characters, mixed case, a number and a symbol.');
                }

                if (User::query()->where('email', $email)->orWhere('phone', $data['phone'])->exists()) {
                    throw new RuntimeException('Bootstrap identity collides with an existing account. No account was changed.');
                }

                $user = new User($data);
                $user->role = UserRole::Admin;
                $user->setAttribute('bootstrap_key', self::BOOTSTRAP_KEY);
                // The operator supplies a trusted deployment identity; no public signup or email is sent.
                $user->email_verified_at = Carbon::now();
                $user->save();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Bootstrap identity conflicts with an existing or concurrently created account. No account was changed; review configuration and retry.');
        }
    }
}
