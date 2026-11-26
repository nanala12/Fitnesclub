<?php

namespace App\Models;

use App\Casts\IntegerGrosz;
use App\Database\SalesHistoryBuilder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesChannel;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property string $holder_first_name
 * @property string $holder_last_name
 * @property array<string, mixed> $plan_snapshot
 * @property int $amount_grosz
 * @property OrderStatus $status
 * @property SalesChannel $sales_channel
 * @property PaymentMethod $payment_method
 * @property CarbonImmutable $ordered_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $regulation_accepted_at
 * @property string|null $reception_request_id
 */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    protected $hidden = ['checkout_request_id', 'checkout_fingerprint', 'reception_request_id', 'purchaser_first_name', 'purchaser_last_name', 'purchaser_email', 'purchaser_phone', 'holder_first_name', 'holder_last_name', 'holder_email', 'holder_phone', 'recipient_email'];

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (($order->getAttributes()['public_reference'] ?? null) === null) {
                $order->public_reference = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['plan_snapshot' => 'array', 'amount_grosz' => IntegerGrosz::class, 'sales_channel' => SalesChannel::class, 'payment_method' => PaymentMethod::class, 'status' => OrderStatus::class, 'ordered_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'regulation_accepted_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function immutableHistoryFields(): array
    {
        return ['checkout_request_id', 'checkout_fingerprint', 'public_reference', 'reception_request_id', 'purchaser_user_id', 'holder_user_id', 'membership_plan_id', 'purchaser_first_name', 'purchaser_last_name', 'purchaser_email', 'purchaser_phone', 'holder_first_name', 'holder_last_name', 'holder_email', 'holder_phone', 'recipient_email', 'plan_snapshot', 'amount_grosz', 'currency', 'sales_channel', 'payment_method', 'ordered_at', 'created_by_user_id'];
    }

    /** @return list<string> */
    public function historyFieldsSetOnce(): array
    {
        return ['paid_at', 'accepted_regulation_version_id', 'regulation_accepted_at', 'renewal_source_membership_id'];
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchaser_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<RegulationVersion, $this> */
    public function acceptedRegulation(): BelongsTo
    {
        return $this->belongsTo(RegulationVersion::class, 'accepted_regulation_version_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    /** @return HasOne<Membership, $this> */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class, 'order_id');
    }
}
