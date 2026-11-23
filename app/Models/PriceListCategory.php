<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceListCategory extends Model
{
    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer', 'revision' => 'integer'];
    }

    /** @return HasMany<PriceListService,$this> */
    public function services(): HasMany
    {
        return $this->hasMany(PriceListService::class, 'category', 'key');
    }

    /** @return HasMany<MembershipPlan,$this> */
    public function plans(): HasMany
    {
        return $this->hasMany(MembershipPlan::class, 'category', 'key');
    }

    /** @return array<int,array{value:string,label:string}> */
    public static function options(bool $publishedOnly = false): array
    {
        return self::query()->when($publishedOnly, fn ($query) => $query->where('is_published', true))->orderBy('sort_order')->orderBy('id')->get(['key', 'name'])->map(fn (self $category): array => ['value' => $category->key, 'label' => $category->name])->all();
    }
}
