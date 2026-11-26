<?php

namespace App\Models;

use App\Database\SalesHistoryBuilder;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\MembershipEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $used_at */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class MembershipEntry extends Model
{
    /** @use HasFactory<MembershipEntryFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
