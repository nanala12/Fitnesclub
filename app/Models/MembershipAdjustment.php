<?php

namespace App\Models;

use App\Database\SalesHistoryBuilder;
use App\Models\Concerns\PreservesSalesHistory;
use Database\Factories\MembershipAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class MembershipAdjustment extends Model
{
    /** @use HasFactory<MembershipAdjustmentFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delta_entries' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function sourceMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'source_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
