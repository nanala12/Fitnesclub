<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\NewsPost;
use App\Rules\CmsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NewsPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', NewsPost::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1']];
        if ($this->isMethod('delete')) {
            return [...$rules, 'current_media_id' => ['present', 'nullable', 'integer', 'min:1']];
        }
        if ($this->routeIs('admin.cms.news.visibility')) {
            return [...$rules, 'is_published' => ['required', 'boolean']];
        }

        return [...$rules,
            'title' => ['required', 'string', 'max:200'], 'excerpt' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:50000'], 'is_published' => ['required', 'boolean'],
            'published_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'image' => ['nullable', new CmsImage, Rule::prohibitedIf($this->boolean('remove_image'))],
            'alt_text' => ['nullable', 'string', 'max:255'], 'remove_image' => ['sometimes', 'boolean'],
            'current_media_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }
}
