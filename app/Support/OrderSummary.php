<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\Membership;
use App\Models\Order;

class OrderSummary
{
    /** Read-only presentation of persisted purchase state; never verifies a provider transaction.
     * @return array<string, mixed>
     */
    public function make(Order $order, bool $paymentAvailable, bool $history = false): array
    {
        $order->loadMissing(['payments', 'membership']);
        $payments = $order->payments;
        $confirmed = $payments->filter(fn ($payment): bool => $payment->status === PaymentStatus::Confirmed);
        $payment = $payments->sortByDesc('id')->first();
        $membership = $order->membership;
        $provider = $order->sales_channel === SalesChannel::Online ? PaymentProvider::Przelewy24 : PaymentProvider::Reception;
        $consistent = $payments->every(fn ($attempt): bool => $attempt->provider === $provider && $attempt->method === $order->payment_method
            && $attempt->amount_grosz === $order->amount_grosz && $attempt->currency === $order->currency);
        $paid = $consistent && $order->status === OrderStatus::Paid && $order->paid_at !== null && $confirmed->count() === 1
            && $confirmed->first()->confirmed_at !== null && $confirmed->first()->amount_grosz === $order->amount_grosz
            && $confirmed->first()->currency === $order->currency && $confirmed->first()->method === $order->payment_method
            && $membership !== null;
        $uncertain = $payments->contains(fn ($attempt): bool => in_array($attempt->status, [PaymentStatus::Unknown, PaymentStatus::Initiated, PaymentStatus::Confirmed], true));
        $state = match (true) {
            $paid => $this->membershipState($membership),
            ! $consistent || $order->status === OrderStatus::Paid || $uncertain => 'checking_payment',
            $order->status === OrderStatus::Cancelled => 'cancelled',
            $order->status === OrderStatus::Failed => 'failed',
            $payment?->status === PaymentStatus::Cancelled => 'cancelled',
            $payment?->status === PaymentStatus::Failed => 'failed',
            $order->sales_channel === SalesChannel::Reception => 'awaiting_reception',
            default => 'awaiting_payment',
        };
        $canPay = $order->sales_channel === SalesChannel::Online && $order->payment_method === PaymentMethod::Przelewy24
            && $order->status === OrderStatus::Pending && $order->paid_at === null && $membership === null && $consistent && ! $uncertain
            && (($payment?->status === PaymentStatus::Pending && $payment->provider_token !== null)
                || (($payment === null || in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true)) && $payments->count() < 3));

        return [
            'reference' => $order->public_reference, 'planName' => $order->plan_snapshot['name'], 'priceLabel' => PlanPrice::label($order->amount_grosz),
            'state' => $state, 'paid' => $paid, 'canPay' => $canPay && $paymentAvailable, 'paymentAvailable' => $paymentAvailable,
            'isGuest' => $order->purchaser_user_id === null,
            'salesChannel' => $order->sales_channel->value, 'paymentMethod' => $order->payment_method->value,
            'orderedAt' => $order->ordered_at->toISOString(), 'paidAt' => $paid ? $order->paid_at->toISOString() : null,
            'startsOn' => $paid ? $membership->starts_on?->toDateString() : null,
            'expiresOn' => $paid ? $membership->expires_on?->toDateString() : null,
            'entriesRemaining' => $paid ? $membership->entries_remaining : null,
            'showUrl' => route($history ? 'orders.show' : 'checkout.show', $order->public_reference, absolute: false),
            'pdfUrl' => ! $paid ? null : ($history ? route('memberships.pdf', $membership->id, absolute: false) : route('checkout.pdf', $order->public_reference, absolute: false)),
        ];
    }

    private function membershipState(Membership $membership): string
    {
        $today = now('Europe/Warsaw')->toDateString();
        if ($membership->starts_on === null) {
            return 'pending_activation';
        }
        if ($membership->starts_on->toDateString() > $today) {
            return 'scheduled';
        }
        if ($membership->expires_on !== null && $membership->expires_on->toDateString() < $today) {
            return 'expired';
        }
        if ($membership->entries_remaining !== null && $membership->entries_remaining <= 0) {
            return 'exhausted';
        }

        return 'active';
    }
}
