<?php

namespace App\Models;

use App\Database\SalesHistoryBuilder;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $plan_snapshot
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable|null $activated_at
 * @property int|null $entries_granted
 * @property int|null $entries_remaining
 */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    protected $hidden = ['holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['plan_snapshot' => 'array', 'starts_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'activated_at' => 'immutable_datetime', 'entries_granted' => 'integer', 'entries_remaining' => 'integer'];
    }

    /** @return list<string> */
    public function immutableHistoryFields(): array
    {
        return ['order_id', 'membership_plan_id', 'holder_user_id', 'renewed_from_id', 'renewal_root_membership_id', 'holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email', 'plan_snapshot',
            ...($this->activated_at === null && $this->renewed_from_id === null ? [] : ['starts_on', 'expires_on', 'activated_at', 'activated_by_user_id'])];
    }

    /** @return list<string> */
    public function historyFieldsSetOnce(): array
    {
        return ['starts_on', 'expires_on', 'activated_at', 'activated_by_user_id'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'renewed_from_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    /** @return HasMany<Membership, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(Membership::class, 'renewed_from_id');
    }

    /** @return HasMany<MembershipEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(MembershipEntry::class, 'membership_id');
    }

    /** @return HasMany<MembershipAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(MembershipAdjustment::class, 'membership_id');
    }

    /** @return HasMany<DocumentDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DocumentDelivery::class, 'membership_id');
    }
}
