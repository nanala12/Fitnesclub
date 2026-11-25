<?php

namespace App\Http\Requests\Admin;

use App\Models\DocumentDelivery;
use Illuminate\Foundation\Http\FormRequest;

class DocumentResendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', DocumentDelivery::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = ['request_id' => ['required', 'uuid'], 'confirm_resend' => ['required', 'accepted']];
        foreach (['recipient_email', 'email', 'membership_id', 'root_delivery_id', 'requested_by_user_id', 'storage_path', 'status', 'attempts_count'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
