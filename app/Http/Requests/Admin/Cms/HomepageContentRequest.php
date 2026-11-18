<?php

namespace App\Http\Requests\Admin\Cms;

use App\Actions\PublicSite\PublicSiteContent;
use App\Enums\HomepageSection;
use App\Rules\CmsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class HomepageContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section instanceof HomepageSection && ($this->user()?->can('manage', $section->model()) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var HomepageSection $section */
        $section = $this->route('section');
        $rules = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1']];
        if ($this->isMethod('delete')) {
            return [...$rules, 'current_media_id' => ['present', 'nullable', 'integer', 'min:1']];
        }
        if ($this->routeIs('admin.cms.homepage.move')) {
            return [...$rules, 'direction' => ['required', Rule::in(['up', 'down'])]];
        }
        if ($this->routeIs('admin.cms.homepage.visibility')) {
            return [...$rules, 'is_published' => ['required', 'boolean']];
        }

        return [
            ...$rules,
            'title' => [$section === HomepageSection::Tiles ? 'required' : 'nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', $section === HomepageSection::Tiles ? 'max:500' : 'max:1000'],
            'link_url' => ['nullable', 'string', 'max:500', 'regex:'.PublicSiteContent::PUBLIC_LINK_PATTERN],
            'is_published' => ['required', 'boolean'],
            'image' => [Rule::requiredIf($section === HomepageSection::Slides && $this->routeIs('admin.cms.homepage.store')), 'nullable', new CmsImage, Rule::prohibitedIf($this->boolean('remove_image'))],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['sometimes', 'boolean'],
            'current_media_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('section') === HomepageSection::Slides && $this->boolean('remove_image')) {
                $validator->errors()->add('remove_image', 'Slajd musi mieć zdjęcie. Aby je usunąć, usuń cały slajd.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['image.required' => 'Wybierz zdjęcie slajdu.', 'image.prohibited' => 'Wybierz zastąpienie albo usunięcie zdjęcia.', 'link_url.regex' => 'Wybierz adres podstrony tego serwisu, np. /oferta lub /cennik.'];
    }
}
