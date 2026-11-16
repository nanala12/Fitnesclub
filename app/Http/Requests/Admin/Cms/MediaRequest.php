<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\MediaAsset;
use App\Rules\CmsImage;
use Illuminate\Foundation\Http\FormRequest;

class MediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', MediaAsset::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return ['current_media_id' => ['required', 'integer', 'min:1']];
        }

        return ['image' => ['required', new CmsImage], 'alt_text' => ['nullable', 'string', 'max:255'], 'current_media_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
