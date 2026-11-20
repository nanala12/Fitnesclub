<?php

namespace App\Models;

use App\Casts\IntegerGrosz;
use App\Enums\PlanKind;
use Database\Factories\MembershipPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PlanKind $kind
 * @property int $price_grosz
 * @property array<string, mixed>|null $terms
 * @property int|null $duration_months
 * @property int|null $included_entries
 */
class MembershipPlan extends Model
{
    /** @use HasFactory<MembershipPlanFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => PlanKind::class, 'price_grosz' => IntegerGrosz::class, 'duration_months' => 'integer', 'included_entries' => 'integer', 'terms' => 'array', 'is_active' => 'boolean', 'is_public' => 'boolean', 'is_featured' => 'boolean', 'sort_order' => 'integer'];
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
