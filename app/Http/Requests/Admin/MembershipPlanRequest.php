<?php

namespace App\Http\Requests\Admin;

use App\Enums\PlanKind;
use App\Models\MembershipPlan;
use App\Support\PlanPrice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', MembershipPlan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1']];
        if ($this->routeIs('admin.plans.visibility')) {
            return [...$rules, 'is_public' => ['required', 'boolean']];
        }
        if ($this->isMethod('delete')) {
            return $rules;
        }

        return [...$rules,
            'category' => ['sometimes', 'required', 'string', Rule::exists('price_list_categories', 'key')], 'price_list_group_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::enum(PlanKind::class)],
            'price' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (PlanPrice::parse($value) === null) {
                    $fail('Wpisz cenę w złotych, np. 155,50, z najwyżej dwiema cyframi po przecinku i w obsługiwanym zakresie.');
                }
            }],
            'duration_months' => [Rule::requiredIf($this->input('kind') === 'time'), Rule::prohibitedIf($this->input('kind') === 'entries'), 'nullable', 'integer', 'min:1', 'max:65535'],
            'included_entries' => [Rule::requiredIf($this->input('kind') === 'entries'), Rule::prohibitedIf($this->input('kind') === 'time'), 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'is_active' => ['required', 'boolean'], 'is_public' => ['required', 'boolean'], 'is_featured' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'price_grosz' => ['prohibited'], 'currency' => ['prohibited'], 'terms' => ['prohibited'],
            'created_by_user_id' => ['prohibited'], 'orders' => ['prohibited'],
        ];
    }
}
