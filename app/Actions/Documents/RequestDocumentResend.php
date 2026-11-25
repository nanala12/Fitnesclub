<?php

namespace App\Actions\Documents;

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\LockMembershipOrder;
use App\Actions\Payments\RequireConfirmedOrderPayment;
use App\Enums\AuditAction;
use App\Enums\DeliveryStatus;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestDocumentResend
{
    public function __construct(private LockMembershipOrder $orders, private RequireConfirmedOrderPayment $payment,
        private RecordAudit $audit, private DispatchDocumentDelivery $dispatch) {}

    public function handle(User $actor, int $deliveryId, string $requestId): DocumentDelivery
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Odśwież listę i ponów żądanie.']);
        }
        $requestId = strtolower($requestId);

        try {
            return DB::transaction(function () use ($actor, $deliveryId, $requestId): DocumentDelivery {
                $actor = $actor->exists ? User::query()->whereKey($actor->id)->lockForUpdate()->first() : null;
                if ($actor === null) {
                    throw new AuthorizationException;
                }
                Gate::forUser($actor)->authorize('manage', DocumentDelivery::class);
                $locator = DocumentDelivery::query()->findOrFail($deliveryId);
                $memberLocator = Membership::query()->findOrFail($locator->membership_id);
                $order = $this->orders->handle($memberLocator);
                $this->payment->handle($order);
                $rootId = $locator->root_delivery_id ?? $locator->id;
                $root = DocumentDelivery::query()->whereKey($rootId)->lockForUpdate()->firstOrFail();
                if ($root->root_delivery_id !== null || $root->membership_id !== $locator->membership_id || $root->kind !== 'membership_pdf' || $root->recipient_email !== $order->recipient_email) {
                    throw ValidationException::withMessages(['delivery' => 'Dane wysyłki wymagają sprawdzenia.']);
                }
                $existing = DocumentDelivery::query()->where('request_id', $requestId)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->root_delivery_id !== $root->id || $existing->requested_by_user_id !== $actor->id) {
                        throw ValidationException::withMessages(['request_id' => 'To żądanie zostało już użyte. Odśwież listę.']);
                    }

                    return $existing;
                }
                $busy = DocumentDelivery::query()->where(fn ($query) => $query->whereKey($root->id)->orWhere('root_delivery_id', $root->id))
                    ->where(fn ($query) => $query->where(fn ($active) => $active->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Processing])->where('needs_review', false))
                        ->orWhere(fn ($retry) => $retry->where('status', DeliveryStatus::Failed)->where('needs_review', false)->where('attempts_count', '<', DocumentDelivery::MAX_ATTEMPTS)))
                    ->lockForUpdate()->exists();
                if ($busy) {
                    throw ValidationException::withMessages(['delivery' => 'Wysyłka lub automatyczne ponowienie już trwa. Odśwież stan za chwilę.']);
                }
                $delivery = new DocumentDelivery;
                $delivery->forceFill(['membership_id' => $root->membership_id, 'root_delivery_id' => $root->id, 'requested_by_user_id' => $actor->id,
                    'request_id' => $requestId, 'kind' => 'membership_pdf', 'recipient_email' => $root->recipient_email,
                    'idempotency_key' => 'membership:'.$root->membership_id.':resend:'.$requestId,
                    'scheduled_for' => now(), 'status' => DeliveryStatus::Pending, 'sent_at' => null, 'storage_path' => null])->save();
                $this->audit->handle(AuditAction::DocumentResendRequested, $delivery->id, $actor, null, null, ['request_id' => $requestId]);
                $this->dispatch->afterCommit($delivery->id);

                return $delivery;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['request_id' => 'To żądanie zostało już użyte. Odśwież listę.']);
        }
    }
}
