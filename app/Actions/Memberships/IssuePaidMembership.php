<?php

namespace App\Actions\Memberships;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\PlanKind;
use App\Enums\SalesChannel;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IssuePaidMembership
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * Internal boundary after trusted payment verification, never a browser-return handler.
     * Pass an authorized staff actor or explicitly null for a trusted system operation.
     * This action does not confirm payments or mark orders paid.
     */
    public function handle(int $orderId, ?User $actor): Membership
    {
        return DB::transaction(function () use ($orderId, $actor): Membership {
            if ($actor !== null) {
                if (! $actor->exists) {
                    throw new AuthorizationException;
                }
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if ($actor === null) {
                    throw new AuthorizationException;
                }
                Gate::forUser($actor)->authorize('issue', Membership::class);
            }
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payments = $order->payments()->where('status', PaymentStatus::Confirmed)->orderBy('id')->lockForUpdate()->get();
            if ($order->status !== OrderStatus::Paid || $order->paid_at === null || $payments->count() !== 1) {
                $this->invalidPayment();
            }
            $payment = $payments->sole();
            $this->requireMatchingPayment($order, $payment);
            $this->requireSnapshot($order);

            $existing = Membership::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $membership = new Membership;
            $attributes = ['order_id' => $order->id, 'renewed_from_id' => null,
                'starts_on' => null, 'expires_on' => null, 'activated_at' => null, 'activated_by_user_id' => null,
                'entries_granted' => $order->plan_snapshot['included_entries'], 'entries_remaining' => $order->plan_snapshot['included_entries']];
            foreach (['membership_plan_id', 'holder_user_id', 'holder_first_name', 'holder_last_name', 'holder_phone', 'holder_email', 'plan_snapshot'] as $field) {
                $attributes[$field] = $order->getAttribute($field);
            }
            $membership->forceFill($attributes)->save();

            // Durable outbox: future delivery workers can retry without issuing another pass.
            $delivery = new DocumentDelivery;
            $delivery->forceFill(['membership_id' => $membership->id, 'kind' => 'membership_pdf',
                'recipient_email' => $order->recipient_email, 'idempotency_key' => 'membership:'.$membership->id.':initial-pdf',
                'scheduled_for' => now(), 'status' => DeliveryStatus::Pending, 'sent_at' => null, 'storage_path' => null])->save();
            $this->audit->handle(AuditAction::MembershipIssued, $membership->id, $actor, null, ['price_grosz' => $order->amount_grosz]);

            return $membership;
        }, 3);
    }

    private function requireMatchingPayment(Order $order, Payment $payment): void
    {
        if ($payment->confirmed_at === null || $payment->amount_grosz !== $order->amount_grosz
            || $payment->currency !== $order->currency || $payment->method !== $order->payment_method) {
            $this->invalidPayment();
        }
        $validChannel = match ($order->sales_channel) {
            SalesChannel::Online => $payment->provider === PaymentProvider::Przelewy24 && $payment->method === PaymentMethod::Przelewy24
                && $order->accepted_regulation_version_id !== null && $order->regulation_accepted_at !== null,
            SalesChannel::Reception => $payment->provider === PaymentProvider::Reception
                && in_array($payment->method, [PaymentMethod::Cash, PaymentMethod::Card], true)
                && $payment->confirmed_by_user_id !== null && $order->created_by_user_id !== null,
        };
        if (! $validChannel) {
            $this->invalidPayment();
        }
    }

    private function requireSnapshot(Order $order): void
    {
        $snapshot = $order->plan_snapshot;
        $kind = is_string($snapshot['kind'] ?? null) ? PlanKind::tryFrom($snapshot['kind']) : null;
        $months = $snapshot['duration_months'] ?? null;
        $entries = $snapshot['included_entries'] ?? null;
        $validTerms = match ($kind) {
            PlanKind::Time => is_int($months) && $months >= 1 && $months <= 65535 && $entries === null,
            PlanKind::Entries => $months === null && is_int($entries) && $entries >= 1 && $entries <= 4294967295,
            default => false,
        };
        if (! $validTerms || ! array_key_exists('included_entries', $snapshot) || ! array_key_exists('duration_months', $snapshot)
            || ($snapshot['snapshot_version'] ?? null) !== 1
            || ! is_string($snapshot['name'] ?? null) || trim($snapshot['name']) === ''
            || ($snapshot['price_grosz'] ?? null) !== $order->amount_grosz || ($snapshot['currency'] ?? null) !== $order->currency) {
            throw ValidationException::withMessages(['order' => 'Zapisane warunki zamówienia wymagają sprawdzenia przed wydaniem karnetu.']);
        }
    }

    private function invalidPayment(): never
    {
        throw ValidationException::withMessages(['payment' => 'Wydanie karnetu wymaga zgodnej, potwierdzonej płatności i opłaconego zamówienia.']);
    }
}
