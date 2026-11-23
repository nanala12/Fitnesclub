<?php

namespace App\Http\Requests\Admin;

use App\Models\PriceListCategory;
use App\Support\PlanPrice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', PriceListCategory::class) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:0'], 'page' => ['sometimes', 'integer', 'min:1'], 'category_page' => ['sometimes', 'integer', 'min:1']];
        if ($this->routeIs('admin.pricing.visibility')) {
            return [...$rules, 'is_published' => ['required', 'boolean']];
        }
        if ($this->isMethod('delete')) {
            return $rules;
        }
        $rules += ['name' => ['required', 'string', 'max:180'], 'is_published' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295']];
        if ($this->route('type') === 'kategorie') {
            return [...$rules, 'key' => ['prohibited']];
        }

        return [...$rules, 'category' => ['required', 'string', Rule::exists('price_list_categories', 'key')], 'price_list_group_id' => ['prohibited'],
            'price' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (PlanPrice::parse($value) === null) {
                    $fail('Wpisz poprawną cenę w złotych, np. 120,50.');
                }
            }], 'price_grosz' => ['prohibited'], 'currency' => ['prohibited']];
    }
}
