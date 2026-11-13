<?php

namespace App\Actions\Employees;

use App\Enums\UserRole;
use App\Jobs\SendEmployeeInvitation;
use App\Jobs\SendProfileEmailVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageEmployee
{
    public function __construct(private RecordEmployeeAudit $audit) {}

    /** @param array{first_name: string, last_name: string, email: string, phone: string, role: string} $data */
    public function save(User $actor, ?User $employee, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $employee, $data): User {
                $actor = $this->lockActor($actor);
                $account = $employee === null ? new User : User::query()->lockForUpdate()->findOrFail($employee->id);
                Gate::forUser($actor)->authorize($employee === null ? 'createEmployee' : 'manageEmployee', $employee === null ? User::class : $account);
                $before = $employee === null ? null : $account->getAttributes();
                $previousIdentity = clone $account;
                $account->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone'])));
                $emailChanged = $employee !== null && $account->isDirty('email');
                if ($emailChanged) {
                    Password::broker()->deleteToken($previousIdentity);
                    $account->email_verified_at = null;
                }
                $account->role = UserRole::from($data['role']);
                if ($employee === null) {
                    // No usable password is disclosed; the email owner sets their own via the broker.
                    $account->password = Str::random(64);
                    $account->staff_invited_at = Carbon::now();
                }
                $account->save();
                $this->audit->handle($actor, $account, $employee === null ? 'employee.created' : 'employee.updated', $before);
                if ($employee === null || $emailChanged) {
                    if ($account->hasPendingStaffInvitation()) {
                        SendEmployeeInvitation::dispatch($account->id, sha1($account->email));
                    } elseif ($emailChanged) {
                        SendProfileEmailVerification::dispatch($account->id, sha1($account->email));
                    }
                }

                return $account;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $errors = [];
            foreach (['email', 'phone'] as $field) {
                $query = User::query()->where($field, $data[$field]);
                if ($employee !== null) {
                    $query->whereKeyNot($employee->id);
                }
                if ($query->exists()) {
                    $errors[$field] = __('validation.unique', ['attribute' => __('validation.attributes.'.$field)]);
                }
            }
            if ($errors === []) {
                throw $exception;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public function suspend(User $actor, User $employee, bool $suspended): void
    {
        DB::transaction(function () use ($actor, $employee, $suspended): void {
            $actor = $this->lockActor($actor);
            $account = User::query()->lockForUpdate()->findOrFail($employee->id);
            Gate::forUser($actor)->authorize('manageEmployee', $account);
            if ($account->isSuspended() === $suspended) {
                return;
            }
            $before = $account->getAttributes();
            $account->suspended_at = $suspended ? Carbon::now() : null;
            $account->setRememberToken(Str::random(60));
            $account->save();
            if ($suspended) {
                Password::broker()->deleteToken($account);
                DB::table('sessions')->where('user_id', $account->id)->delete();
            }
            $this->audit->handle($actor, $account, $suspended ? 'employee.suspended' : 'employee.resumed', $before);
        }, attempts: 3);
    }

    public function invite(User $actor, User $employee): void
    {
        DB::transaction(function () use ($actor, $employee): void {
            $actor = $this->lockActor($actor);
            $account = User::query()->lockForUpdate()->findOrFail($employee->id);
            Gate::forUser($actor)->authorize('manageEmployee', $account);
            if (! $account->hasPendingStaffInvitation() || $account->isSuspended()) {
                throw ValidationException::withMessages(['invitation' => 'Zaproszenie można wysłać tylko do niezawieszonego konta oczekującego na ustawienie hasła.']);
            }
            $this->audit->handle($actor, $account, 'employee.invitation_requested', $account->getAttributes());
            SendEmployeeInvitation::dispatch($account->id, sha1($account->email));
        }, attempts: 3);
    }

    private function lockActor(User $actor): User
    {
        // Serialize administrator changes and recheck the actor after acquiring locks.
        User::query()->where('role', UserRole::Admin)->orderBy('id')->lockForUpdate()->get();

        return User::query()->lockForUpdate()->findOrFail($actor->id);
    }
}
