<?php

namespace App\Actions\Memberships;

use App\Actions\Audit\RecordAudit;
use App\Actions\Payments\RequireConfirmedOrderPayment;
use App\Enums\AuditAction;
use App\Models\Membership;
use App\Models\MembershipEntry;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RedeemMembershipEntry
{
    public function __construct(private RecordAudit $audit, private RequireConfirmedOrderPayment $payment) {}

    public function handle(User $actor, int $membershipId, string $requestId): MembershipEntry
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Identyfikator operacji jest nieprawidłowy. Odśwież widok i spróbuj ponownie.']);
        }
        $requestId = strtolower($requestId);

        return DB::transaction(function () use ($actor, $membershipId, $requestId): MembershipEntry {
            if (! $actor->exists) {
                throw new AuthorizationException;
            }
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($actor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('redeem', Membership::class);
            $orderId = Membership::query()->whereKey($membershipId)->firstOrFail(['order_id'])->order_id;
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->payment->handle($order);
            $membership = Membership::query()->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now('UTC');
            $entry = new MembershipEntry;
            try {
                // Insert first to claim the UUID without locking empty index gaps. All validation
                // below shares this transaction, so a rejected use leaves no ledger row behind.
                $entry->forceFill(['membership_id' => $membership->id, 'recorded_by_user_id' => $actor->id,
                    'used_at' => $now, 'created_at' => $now, 'request_id' => $requestId])->save();
            } catch (UniqueConstraintViolationException $exception) {
                $existing = MembershipEntry::query()->where('request_id', $requestId)->lockForUpdate()->first();
                if ($existing === null) {
                    throw $exception;
                }
                if ($existing->membership_id !== $membership->id || $existing->recorded_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['request_id' => 'Ten identyfikator operacji został już wykorzystany. Odśwież widok przed kolejnym wejściem.']);
                }

                return $existing;
            }

            $today = $now->setTimezone('Europe/Warsaw')->toDateString();
            if ($membership->activated_at === null || $membership->starts_on === null || $membership->starts_on->toDateString() > $today) {
                throw ValidationException::withMessages(['membership' => 'Najpierw aktywuj karnet przy pierwszej wizycie.']);
            }
            if ($membership->expires_on !== null && $membership->expires_on->toDateString() < $today) {
                throw ValidationException::withMessages(['membership' => 'Ważność tego karnetu już się zakończyła.']);
            }
            $snapshot = $membership->plan_snapshot;
            if ($snapshot !== $order->plan_snapshot || ($snapshot['snapshot_version'] ?? null) !== 1
                || ($snapshot['kind'] ?? null) !== 'entries' || ($snapshot['duration_months'] ?? null) !== null
                || ! is_int($snapshot['included_entries'] ?? null) || $snapshot['included_entries'] < 1
                || ($snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($snapshot['currency'] ?? null) !== $order->currency
                || $membership->entries_granted === null || $membership->entries_remaining === null
                || $membership->entries_granted < 1 || $membership->entries_remaining > $membership->entries_granted) {
                throw ValidationException::withMessages(['membership' => 'Ten karnet nie ma prawidłowego limitu wejść do odliczania.']);
            }
            $remaining = $membership->entries_remaining;
            if ($remaining < 1) {
                throw ValidationException::withMessages(['membership' => 'Ten karnet nie ma już dostępnych wejść.']);
            }
            $membership->forceFill(['entries_remaining' => $remaining - 1])->save();
            $this->audit->handle(AuditAction::MembershipEntryUsed, $membership->id, $actor,
                ['entries_remaining' => $remaining], ['entries_remaining' => $remaining - 1],
                ['request_id' => $requestId, 'changed_fields' => ['entries_remaining', 'used_at']]);

            return $entry;
        }, 3);
    }
}
