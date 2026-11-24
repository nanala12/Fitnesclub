<?php

namespace App\Actions\Payments;

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\IssuePaidMembership;
use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfirmReceptionPayment
{
    public function __construct(private RecordAudit $audit, private IssuePaidMembership $memberships) {}

    /** Records money already received by staff; never initiates a bank/terminal charge. */
    public function handle(User $actor, int|Order $orderId): Membership
    {
        return DB::transaction(function () use ($actor, $orderId): Membership {
            if (! $actor->exists) {
                throw new AuthorizationException;
            }
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($actor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($actor)->authorize('confirmReception', Order::class);
            // When chaining creation in an outer transaction, its locked return value is
            // a current locator even if an earlier REPEATABLE READ snapshot missed the order.
            $hint = $orderId instanceof Order ? $orderId : Order::query()->findOrFail($orderId);
            if (! $hint->exists) {
                throw ValidationException::withMessages(['order' => 'Wybierz zapisane zamówienie przed potwierdzeniem.']);
            }
            $sourceId = $hint->renewal_source_membership_id;
            if ($sourceId !== null) {
                $source = Membership::query()->findOrFail($sourceId);
                $root = $source->renewal_root_membership_id === null ? $source : Membership::query()->findOrFail($source->renewal_root_membership_id);
                Order::query()->whereKey($root->order_id)->lockForUpdate()->firstOrFail();
            }
            $order = Order::query()->whereKey($hint->id)->lockForUpdate()->firstOrFail();
            if ($order->sales_channel !== SalesChannel::Reception || ! in_array($order->payment_method, [PaymentMethod::Cash, PaymentMethod::Card], true) || $order->created_by_user_id === null) {
                throw ValidationException::withMessages(['order' => 'Ta operacja dotyczy wyłącznie sprzedaży gotówką lub kartą na recepcji.']);
            }
            if ($order->renewal_source_membership_id !== $sourceId) {
                throw ValidationException::withMessages(['renewal' => 'Powiązanie karnetu zostało zmienione. Wczytaj aktualne dane przed potwierdzeniem.']);
            }
            if ($order->status === OrderStatus::Paid) {
                return $this->memberships->handle($order->id, $actor);
            }
            if ($order->status !== OrderStatus::Pending || $order->paid_at !== null || $order->payments()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Stan płatności wymaga sprawdzenia. Nie można ponownie potwierdzić tej sprzedaży.']);
            }
            $now = CarbonImmutable::now('UTC');
            $payment = new Payment;
            $payment->forceFill(['order_id' => $order->id, 'provider' => PaymentProvider::Reception, 'method' => $order->payment_method,
                'status' => PaymentStatus::Confirmed, 'amount_grosz' => $order->amount_grosz, 'currency' => $order->currency,
                'confirmed_at' => $now, 'confirmed_by_user_id' => $actor->id,
                'provider_session_id' => null, 'provider_transaction_id' => null, 'failure_code' => null])->save();
            $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $now])->save();
            $membership = $this->memberships->handle($order->id, $actor);
            $this->audit->handle(AuditAction::ReceptionPaymentConfirmed, $order->id, $actor, null,
                ['price_grosz' => $order->amount_grosz, 'payment_method' => $order->payment_method->value, 'sales_channel' => 'reception'],
                ['request_id' => $order->reception_request_id]);

            return $membership;
        }, 3);
    }
}
