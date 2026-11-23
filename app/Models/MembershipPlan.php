<?php

namespace App\Models;

use App\Casts\IntegerGrosz;
use App\Enums\PlanKind;
use Database\Factories\MembershipPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PlanKind $kind
 * @property int $price_grosz
 * @property array<string, mixed>|null $terms
 * @property int|null $duration_months
 * @property int|null $included_entries
 * @property int $revision
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_featured
 */
class MembershipPlan extends Model
{
    protected $attributes = ['category' => 'gym'];

    /** @use HasFactory<MembershipPlanFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => PlanKind::class, 'price_grosz' => IntegerGrosz::class, 'duration_months' => 'integer', 'included_entries' => 'integer', 'terms' => 'array', 'is_active' => 'boolean', 'is_public' => 'boolean', 'is_featured' => 'boolean', 'sort_order' => 'integer', 'revision' => 'integer'];
    }

    /** @param Builder<MembershipPlan> $query */
    #[Scope]
    protected function publiclyAvailable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true)->whereHas('priceCategory', fn (Builder $category): Builder => $category->where('is_published', true));
    }

    /** @return BelongsTo<PriceListCategory,$this> */
    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceListCategory::class, 'category', 'key');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'membership_plan_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'membership_plan_id');
    }
}
