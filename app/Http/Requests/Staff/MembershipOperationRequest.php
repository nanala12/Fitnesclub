<?php

namespace App\Http\Requests\Staff;

use App\Models\Membership;
use Illuminate\Foundation\Http\FormRequest;

class MembershipOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->fresh()?->can('issue', Membership::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['confirmed' => ['required', 'accepted'], 'request_id' => ['required', 'uuid'],
            'status' => ['prohibited'], 'entries_remaining' => ['prohibited'], 'starts_on' => ['prohibited'], 'expires_on' => ['prohibited']];
    }
}
