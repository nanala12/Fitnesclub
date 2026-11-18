<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\GalleryCategory;
use App\Rules\CmsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GalleryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', GalleryCategory::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1'],
            'categories_page' => ['sometimes', 'integer', 'min:1'], 'kategoria' => ['sometimes', 'nullable', 'integer', 'min:1']];
        if ($this->routeIs('admin.cms.gallery.*.move')) {
            return [...$base, 'direction' => ['required', Rule::in(['up', 'down'])]];
        }
        if ($this->routeIs('admin.cms.gallery.*.visibility')) {
            return [...$base, 'is_published' => ['required', 'boolean']];
        }
        if ($this->isMethod('delete')) {
            return $this->routeIs('admin.cms.gallery.images.*')
                ? [...$base, 'current_media_id' => ['required', 'integer', 'min:1']] : $base;
        }
        if ($this->routeIs('admin.cms.gallery.categories.*')) {
            return [...$base, 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'], 'is_published' => ['required', 'boolean']];
        }

        return [...$base, 'gallery_category_id' => ['required', 'integer', 'min:1', Rule::exists('gallery_categories', 'id')],
            'is_published' => ['required', 'boolean'], 'alt_text' => ['nullable', 'string', 'max:255'],
            'image' => [Rule::requiredIf($this->routeIs('admin.cms.gallery.images.store')), 'nullable', new CmsImage],
            'current_media_id' => ['present', 'nullable', 'integer', 'min:1'],
            'continue_upload' => ['sometimes', 'boolean']];
    }
}
