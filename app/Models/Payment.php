<?php

namespace App\Models;

use App\Casts\IntegerGrosz;
use App\Database\SalesHistoryBuilder;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $amount_grosz
 * @property PaymentStatus $status
 * @property PaymentProvider $provider
 * @property PaymentMethod $method
 * @property CarbonImmutable|null $confirmed_at
 */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    protected $hidden = ['provider_session_id', 'provider_transaction_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_grosz' => IntegerGrosz::class, 'provider' => PaymentProvider::class, 'method' => PaymentMethod::class, 'status' => PaymentStatus::class, 'confirmed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function immutableHistoryFields(): array
    {
        return ['order_id', 'provider', 'method', 'amount_grosz', 'currency'];
    }

    /** @return list<string> */
    public function historyFieldsSetOnce(): array
    {
        return ['provider_session_id', 'provider_transaction_id', 'confirmed_at', 'confirmed_by_user_id'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
