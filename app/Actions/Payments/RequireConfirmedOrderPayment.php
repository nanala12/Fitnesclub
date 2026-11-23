<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class RequireConfirmedOrderPayment
{
    /** The caller must lock and reload the order before checking its trusted payment state. */
    public function handle(Order $order): Payment
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payment verification reads require a business transaction and a locked order.');
        }
        $payments = $order->payments()->where('status', PaymentStatus::Confirmed)->orderBy('id')->lockForUpdate()->get();
        if ($order->status !== OrderStatus::Paid || $order->paid_at === null || $payments->count() !== 1) {
            $this->invalidPayment();
        }
        $payment = $payments->sole();

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

        return $payment;
    }

    private function invalidPayment(): never
    {
        throw ValidationException::withMessages(['payment' => 'Karnet musi mieć potwierdzoną płatność zgodną z opłaconym zamówieniem.']);
    }
}
