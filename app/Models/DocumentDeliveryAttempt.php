<?php

namespace App\Models;

use App\Database\SalesHistoryBuilder;
use App\Models\Concerns\PreservesSalesHistory;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $attempted_at
 * @property int $attempt_number
 */
#[UseEloquentBuilder(SalesHistoryBuilder::class)]
class DocumentDeliveryAttempt extends Model
{
    /** @use HasFactory<DocumentDeliveryAttemptFactory> */
    use HasFactory;

    use PreservesSalesHistory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'attempted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DocumentDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DocumentDelivery::class, 'document_delivery_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
