<?php

namespace App\Http\Requests\Admin\Cms;

use App\Models\ClubSetting;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClubContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', ClubSetting::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_phone'))) {
            $this->merge(['contact_phone' => PhoneNumber::normalize($this->input('contact_phone'))]);
        }
        if (is_string($this->input('contact_email'))) {
            $this->merge(['contact_email' => mb_strtolower($this->input('contact_email'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $base = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1'], 'types_page' => ['sometimes', 'integer', 'min:1']];
        if ($this->isMethod('delete')) {
            return $base;
        }
        if ($this->routeIs('admin.cms.schedule.visibility')) {
            return [...$base, 'is_schedule_visible' => ['required', 'boolean']];
        }
        if ($this->routeIs('admin.cms.schedule.*.visibility')) {
            return [...$base, 'is_published' => ['required', 'boolean']];
        }
        if ($this->routeIs('admin.cms.contact.update')) {
            return [...$base,
                'club_name' => ['nullable', 'string', 'max:150'], 'contact_email' => ['nullable', 'string', 'email', 'max:255'],
                'contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
                'street_address' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'string', 'max:20'], 'city' => ['nullable', 'string', 'max:150'],
                'map_url' => ['nullable', 'string', 'max:1000', 'url:https', function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null || preg_match('/[\x00-\x20\x7f\\\\]/', $value))) {
                        $fail('Podaj bezpieczny odnośnik HTTPS bez danych logowania.');
                    }
                }],
                'hours' => ['required', 'array', 'size:7'], 'hours.*' => ['required', 'array:weekday,status,opens_at,closes_at'],
                'hours.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'], 'hours.*.status' => ['required', Rule::in(['unknown', 'open', 'closed'])],
                'hours.*.opens_at' => ['exclude_unless:hours.*.status,open', 'required', 'date_format:H:i'],
                'hours.*.closes_at' => ['exclude_unless:hours.*.status,open', 'required', 'date_format:H:i', 'after:hours.*.opens_at'],
            ];
        }
        if ($this->routeIs('admin.cms.schedule.types.*')) {
            return [...$base, 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'], 'is_published' => ['required', 'boolean']];
        }

        return [...$base, 'class_type_id' => ['required', 'integer', Rule::exists('class_types', 'id')], 'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'instructor_name' => ['nullable', 'string', 'max:150'], 'is_published' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['contact_phone.regex' => 'Podaj numer z prefiksem kraju, np. +48, i od 7 do 15 cyfr.',
            'hours.*.closes_at.after' => 'Godzina zamknięcia musi być późniejsza niż otwarcia.',
            'ends_at.after' => 'Koniec zajęć musi być późniejszy niż początek.'];
    }
}
