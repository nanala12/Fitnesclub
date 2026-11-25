<?php

namespace App\Actions\Documents;

use App\Enums\DeliveryStatus;
use App\Jobs\SendMembershipDocument;
use App\Models\DocumentDelivery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchDocumentDelivery
{
    public function afterCommit(int $deliveryId): void
    {
        DB::afterCommit(fn () => $this->handle($deliveryId));
    }

    public function handle(int $deliveryId): bool
    {
        try {
            $reservation = DB::transaction(function () use ($deliveryId): ?array {
                $delivery = DocumentDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
                if ($delivery === null || $delivery->kind !== 'membership_pdf' || $delivery->needs_review
                    || ! in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::Failed], true)
                    || $delivery->attempts_count >= DocumentDelivery::MAX_ATTEMPTS
                    || ($delivery->queued_at !== null && $delivery->queued_at->addSeconds(60)->isFuture())) {
                    return null;
                }
                $delivery->forceFill(['queued_at' => now()])->save();

                return [$delivery->attempts_count, $delivery->scheduled_for];
            }, 3);
            if ($reservation === null) {
                return false;
            }
            Bus::dispatch((new SendMembershipDocument($deliveryId, $reservation[0]))->delay($reservation[1]));

            return true;
        } catch (Throwable) {
            Log::warning('Membership delivery dispatch requires retry.', ['delivery_id' => $deliveryId]);

            return false;
        }
    }
}
