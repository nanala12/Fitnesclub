<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
            'role' => ['prohibited'],
            'suspended_at' => ['prohibited'],
            'is_active' => ['prohibited'],
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'bootstrap_key' => ['prohibited'],
            'permissions' => ['prohibited'],
            'staff_invited_at' => ['prohibited'],
            'staff_invitation_accepted_at' => ['prohibited'],
        ];
    }
}
