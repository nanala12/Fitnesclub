<?php

namespace App\Models;

use App\Database\SalesHistoryBuilder;
use App\Enums\DeliveryStatus;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property DeliveryStatus $status
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable|null $sent_at
 */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class DocumentDelivery extends Model
{
    /** @use HasFactory<DocumentDeliveryFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    protected $hidden = ['recipient_email', 'storage_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'status' => DeliveryStatus::class];
    }

    /** @return list<string> */
    public function immutableHistoryFields(): array
    {
        return ['membership_id', 'kind', 'recipient_email', 'idempotency_key'];
    }

    /** @return list<string> */
    public function historyFieldsSetOnce(): array
    {
        return ['sent_at'];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    /** @return HasMany<DocumentDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(DocumentDeliveryAttempt::class, 'document_delivery_id');
    }
}
