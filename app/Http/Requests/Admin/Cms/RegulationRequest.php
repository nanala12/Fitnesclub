<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\RegulationVersion;
use App\Rules\CmsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', RegulationVersion::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1']];
        if ($this->isMethod('delete') || $this->routeIs('admin.cms.regulations.publish')) {
            return $base;
        }

        return [...$base, 'body' => ['required', 'string', 'max:50000'],
            'current_media_id' => ['present', 'nullable', 'integer', 'min:1'],
            'image' => ['nullable', new CmsImage, Rule::prohibitedIf($this->boolean('remove_image'))],
            'remove_image' => ['sometimes', 'boolean'], 'alt_text' => ['nullable', 'string', 'max:255']];
    }
}
