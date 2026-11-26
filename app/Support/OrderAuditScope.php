<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OrderAuditScope
{
    /** @param Builder<AuditLog> $query */
    public function apply(Builder $query, Order $order): void
    {
        $membershipId = $order->membership()->value('id');
        $paymentIds = $order->payments()->pluck('id');
        $deliveryIds = DocumentDelivery::query()->where('membership_id', $membershipId)->select('id');
        $query->where(fn ($events) => $events->where(fn ($subject) => $subject->where('subject_type', 'order')->where('subject_id', $order->id))
            ->orWhere(fn ($subject) => $subject->where('subject_type', 'payment')->whereIn('subject_id', $paymentIds))
            ->orWhere(fn ($subject) => $subject->where('subject_type', 'membership')->whereIn('subject_id', $membershipId === null ? [] : [$membershipId]))
            ->orWhere(fn ($subject) => $subject->where('subject_type', 'document_delivery')->whereIn('subject_id', $deliveryIds)));
    }
}
