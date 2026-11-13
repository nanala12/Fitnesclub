<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof User && ($this->user()?->can('manageEmployee', $employee) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...($this->routeIs('admin.employees.suspension') ? ['suspended' => ['required', 'boolean']] : []),
            ...array_fill_keys(['id', 'user_id', 'role', 'password', 'email', 'is_active', 'suspended_at', 'email_verified_at', 'bootstrap_key', 'staff_invited_at', 'staff_invitation_accepted_at'], ['prohibited']),
        ];
    }
}
