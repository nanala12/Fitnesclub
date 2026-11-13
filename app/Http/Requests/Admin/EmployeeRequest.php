<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof User
            ? ($this->user()?->can('manageEmployee', $employee) ?? false)
            : ($this->user()?->can('createEmployee', User::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            ...$this->profileRules($employee instanceof User ? $employee->id : null),
            'role' => ['required', Rule::in([UserRole::Employee->value, UserRole::Admin->value])],
            ...array_fill_keys(['id', 'user_id', 'password', 'is_active', 'suspended_at', 'email_verified_at', 'bootstrap_key', 'staff_invited_at', 'staff_invitation_accepted_at', 'permissions'], ['prohibited']),
        ];
    }
}
