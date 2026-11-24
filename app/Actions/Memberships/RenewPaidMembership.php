<?php

namespace App\Actions\Memberships;

use App\Actions\Audit\RecordAudit;
use App\Actions\Payments\RequireConfirmedOrderPayment;
use App\Enums\AuditAction;
use App\Enums\DeliveryStatus;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipAdjustment;
use App\Models\Order;
use App\Models\User;
use App\Support\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RenewPaidMembership
{
    public function __construct(private RecordAudit $audit, private RequireConfirmedOrderPayment $payment) {}

    /** Staff selects a source explicitly; system calls require a source already bound to the order. */
    public function handle(?User $actor, int $orderId, int $sourceMembershipId): Membership
    {
        return DB::transaction(function () use ($actor, $orderId, $sourceMembershipId): Membership {
            if ($actor !== null) {
                if (! $actor->exists) {
                    throw new AuthorizationException;
                }
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if ($actor === null) {
                    throw new AuthorizationException;
                }
                Gate::forUser($actor)->authorize('renew', Membership::class);
            }
            // These links are immutable. The root order serializes every purchase in a chain.
            $source = Membership::query()->findOrFail($sourceMembershipId);
            $rootId = $source->renewal_root_membership_id ?? $source->id;
            $root = Membership::query()->findOrFail($rootId);
            $orderIds = array_unique([$orderId, (int) $root->order_id, (int) $source->order_id]);
            sort($orderIds);
            Order::query()->whereKey($root->order_id)->lockForUpdate()->firstOrFail();
            $orders = Order::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $order = $orders->get($orderId);
            if ($order === null) {
                Order::query()->findOrFail($orderId);
                throw new \LogicException('Missing locked renewal order.');
            }
            $this->payment->handle($order);
            if (($order->renewal_source_membership_id !== null && $order->renewal_source_membership_id !== $sourceMembershipId)
                || ($actor === null && $order->renewal_source_membership_id === null)) {
                $this->unsupported('Przedłużenie wymaga wcześniej zatwierdzonego powiązania zakupu z karnetem źródłowym.');
            }
            foreach ($orders as $locked) {
                if ($locked->id !== $order->id) {
                    $this->payment->handle($locked);
                }
            }
            $root = Membership::query()->whereKey($rootId)->lockForUpdate()->firstOrFail();
            $source = Membership::query()->whereKey($sourceMembershipId)->lockForUpdate()->firstOrFail();
            $existing = Membership::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->renewal_root_membership_id !== $root->id) {
                    $this->unsupported('To zamówienie zostało już zrealizowane. Nie można zmienić jego powiązania z karnetem.');
                }

                return $existing;
            }
            $now = CarbonImmutable::now('UTC');
            $today = $now->setTimezone('Europe/Warsaw')->toDateString();
            if ($order->id === $source->order_id || $source->starts_on === null || $source->starts_on->toDateString() > $today
                || ($source->activated_at === null && $source->renewal_root_membership_id === null)
                || ($source->expires_on !== null && $source->expires_on->toDateString() < $today)) {
                $this->unsupported('Przedłużenie wymaga rozpoczętego, niewygasłego karnetu. Zakupów oczekujących na pierwszą aktywację nie łączymy automatycznie.');
            }
            $this->requireHolder($source, $order);
            $this->requireCompatible($source, $order);
            $tail = Membership::query()->where('renewal_root_membership_id', $root->id)->orderByDesc('id')->lockForUpdate()->first() ?? $root;
            if (Membership::query()->where('renewed_from_id', $tail->id)->lockForUpdate()->exists()) {
                $this->unsupported('Historia przedłużeń wymaga sprawdzenia.');
            }
            $this->requireHolder($tail, $order);
            $this->requireCompatible($tail, $order);
            if ($tail->id !== $source->id) {
                $tailOrder = Order::query()->whereKey($tail->order_id)->lockForUpdate()->firstOrFail();
                $this->payment->handle($tailOrder);
            }
            $snapshot = $order->plan_snapshot;
            if (($snapshot['snapshot_version'] ?? null) !== 1 || ! is_string($snapshot['name'] ?? null) || trim($snapshot['name']) === ''
                || ($snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($snapshot['currency'] ?? null) !== $order->currency) {
                $this->unsupported('Zapisane warunki zakupu wymagają sprawdzenia.');
            }
            $kind = $snapshot['kind'];
            $start = $today;
            $expiry = null;
            $grant = null;
            $carry = 0;
            if ($kind === 'time') {
                if ($tail->expires_on === null || $tail->expires_on->toDateString() < $today || ! is_int($snapshot['duration_months'] ?? null)
                    || ($snapshot['included_entries'] ?? null) !== null) {
                    $this->unsupported('Przedłużenie wymaga znanej daty końca i poprawnego okresu zakupu.');
                }
                try {
                    $period = MembershipPeriod::startingOn($tail->expires_on->addDay()->toDateString(), $snapshot['duration_months']);
                } catch (InvalidArgumentException) {
                    $this->unsupported('Nie można wyliczyć ważności z zapisanych warunków zakupu.');
                }
                $start = $period->startsOn;
                $expiry = $period->expiresOn;
            } else {
                if ($tail->starts_on === null || $tail->starts_on->toDateString() > $today
                    || ($tail->activated_at === null && $tail->renewal_root_membership_id === null)
                    || $tail->expires_on !== null || ($snapshot['duration_months'] ?? null) !== null
                    || ! is_int($snapshot['included_entries'] ?? null) || $snapshot['included_entries'] < 1
                    || $tail->entries_granted === null || $tail->entries_remaining === null
                    || $tail->entries_remaining < 0 || $tail->entries_remaining > $tail->entries_granted) {
                    $this->unsupported('Przeniesienie wejść wymaga rozpoczętego karnetu bez daty końca i poprawnego salda.');
                }
                $carry = $tail->entries_remaining;
                $grant = $snapshot['included_entries'] + $carry;
                if ($carry > 2147483647 || $grant > 4294967295) {
                    $this->unsupported('Suma wejść przekracza obsługiwany zakres.');
                }
            }
            if ($order->renewal_source_membership_id === null) {
                $order->forceFill(['renewal_source_membership_id' => $sourceMembershipId])->save();
            }
            $membership = new Membership;
            $attributes = ['order_id' => $order->id, 'renewed_from_id' => $tail->id, 'renewal_root_membership_id' => $root->id,
                'starts_on' => $start, 'expires_on' => $expiry, 'activated_at' => null, 'activated_by_user_id' => null,
                'entries_granted' => $grant, 'entries_remaining' => $grant];
            foreach (['membership_plan_id', 'holder_user_id', 'holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email', 'plan_snapshot'] as $field) {
                $attributes[$field] = $order->getAttribute($field);
            }
            $membership->forceFill($attributes)->save();
            if ($kind === 'entries' && $carry > 0) {
                $tail->forceFill(['entries_remaining' => 0])->save();
                foreach (['out' => [$tail->id, -$carry], 'in' => [$membership->id, $carry]] as $direction => [$memberId, $delta]) {
                    $adjustment = new MembershipAdjustment;
                    $adjustment->forceFill(['membership_id' => $memberId, 'source_membership_id' => $tail->id,
                        'kind' => 'rollover_'.$direction, 'delta_entries' => $delta, 'performed_by_user_id' => $actor?->id,
                        'reason' => null, 'idempotency_key' => 'rollover:'.$tail->id.':'.$membership->id.':'.$direction, 'created_at' => $now])->save();
                }
                $this->audit->handle(AuditAction::MembershipEntriesTransferred, $tail->id, $actor,
                    ['entries_remaining' => $carry], ['entries_remaining' => 0], ['changed_fields' => ['entries_remaining']]);
            }
            $delivery = new DocumentDelivery;
            $delivery->forceFill(['membership_id' => $membership->id, 'kind' => 'membership_pdf', 'recipient_email' => $order->recipient_email,
                'idempotency_key' => 'membership:'.$membership->id.':initial-pdf', 'scheduled_for' => $now,
                'status' => DeliveryStatus::Pending, 'sent_at' => null, 'storage_path' => null])->save();
            $this->audit->handle(AuditAction::MembershipRenewed, $membership->id, $actor, null,
                ['starts_on' => $start, 'expires_on' => $expiry, ...($grant === null ? [] : ['entries_remaining' => $grant])],
                ['changed_fields' => ['starts_on', ...($expiry === null ? ['entries_remaining'] : ['expires_on'])]]);

            return $membership;
        }, 3);
    }

    private function requireCompatible(Membership $source, Order $order): void
    {
        $old = $source->plan_snapshot;
        $new = $order->plan_snapshot;
        if (($old['kind'] ?? null) !== ($new['kind'] ?? null) || ! in_array($old['kind'] ?? null, ['time', 'entries'], true)
            || ($source->membership_plan_id !== $order->membership_plan_id
                && ! ($old['kind'] === 'entries' && ($old['included_entries'] ?? null) === 4 && ($new['included_entries'] ?? null) === 8))) {
            $this->unsupported('Obsługujemy ten sam plan oraz przejście z 4 na 8 wejść. Inne połączenia wymagają ustalenia zasad.');
        }
    }

    private function requireHolder(Membership $source, Order $order): void
    {
        if ($source->holder_user_id !== null || $order->holder_user_id !== null) {
            if ($source->holder_user_id === null || $source->holder_user_id !== $order->holder_user_id) {
                $this->unsupported('Karnety muszą należeć do tego samego posiadacza. Nie łączymy zakupów gościnnych z kontami.');
            }

            return;
        }
        foreach (['holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email'] as $field) {
            if ($source->getAttribute($field) !== $order->getAttribute($field)) {
                $this->unsupported('Dane posiadacza nie są zgodne z wybranym karnetem. Sam numer telefonu nie identyfikuje osoby.');
            }
        }
    }

    private function unsupported(string $message): never
    {
        throw ValidationException::withMessages(['renewal' => $message]);
    }
}
