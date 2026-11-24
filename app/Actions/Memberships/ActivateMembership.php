<?php

namespace App\Actions\Memberships;

use App\Actions\Audit\RecordAudit;
use App\Actions\Payments\RequireConfirmedOrderPayment;
use App\Enums\AuditAction;
use App\Enums\PlanKind;
use App\Models\Membership;
use App\Models\User;
use App\Support\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ActivateMembership
{
    public function __construct(private RecordAudit $audit, private RequireConfirmedOrderPayment $payment, private LockMembershipOrder $orders) {}

    public function handle(User $actor, int $membershipId): Membership
    {
        return DB::transaction(function () use ($actor, $membershipId): Membership {
            if (! $actor->exists) {
                throw new AuthorizationException;
            }
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($actor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('activate', Membership::class);

            // The order link is immutable. Lock in the same order as payment issuance.
            $locator = Membership::query()->whereKey($membershipId)->firstOrFail(['order_id', 'renewal_root_membership_id']);
            $order = $this->orders->handle($locator);
            $this->payment->handle($order);
            $membership = Membership::query()->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            if ($membership->activated_at !== null) {
                return $membership;
            }
            if ($membership->renewed_from_id !== null || $membership->starts_on !== null || $membership->expires_on !== null) {
                throw ValidationException::withMessages(['membership' => 'Ten karnet ma już ustalony okres lub jest przedłużeniem. Nie wymaga ponownej aktywacji przy pierwszej wizycie.']);
            }
            $snapshot = $membership->plan_snapshot;
            if ($snapshot !== $order->plan_snapshot || ($snapshot['snapshot_version'] ?? null) !== 1
                || ($snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($snapshot['currency'] ?? null) !== $order->currency) {
                $this->invalidTerms();
            }
            $kind = is_string($snapshot['kind'] ?? null) ? PlanKind::tryFrom($snapshot['kind']) : null;
            $now = CarbonImmutable::now('UTC');
            $start = $now->setTimezone('Europe/Warsaw')->toDateString();
            $expiry = null;
            if ($kind === PlanKind::Time && is_int($snapshot['duration_months'] ?? null) && ($snapshot['included_entries'] ?? null) === null
                && $membership->entries_granted === null && $membership->entries_remaining === null) {
                try {
                    $period = MembershipPeriod::fromActivation($now, $snapshot['duration_months']);
                } catch (InvalidArgumentException) {
                    $this->invalidTerms();
                }
                $start = $period->startsOn;
                $expiry = $period->expiresOn;
            } elseif ($kind === PlanKind::Entries && ($snapshot['duration_months'] ?? null) === null
                && is_int($snapshot['included_entries'] ?? null) && $snapshot['included_entries'] > 0
                && $membership->entries_granted === $snapshot['included_entries'] && $membership->entries_remaining === $membership->entries_granted) {
                // Entry activation starts no monthly clock and consumes no entry.
            } else {
                $this->invalidTerms();
            }
            $membership->forceFill(['activated_at' => $now, 'activated_by_user_id' => $actor->id, 'starts_on' => $start, 'expires_on' => $expiry])->save();
            $this->audit->handle(AuditAction::MembershipActivated, $membership->id, $actor, null,
                ['starts_on' => $start, 'expires_on' => $expiry], ['changed_fields' => ['activated_at', 'starts_on', ...($expiry === null ? [] : ['expires_on'])]]);

            return $membership;
        }, 3);
    }

    private function invalidTerms(): never
    {
        throw ValidationException::withMessages(['membership' => 'Zapisane warunki karnetu wymagają sprawdzenia przed aktywacją.']);
    }
}
