<?php

namespace App\Actions\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Models\Membership;
use App\Models\Order;
use App\Support\OrderSummary;
use App\Support\PlanPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class AdminDashboard
{
    public function __construct(private OrderSummary $summary) {}

    /** @return array<string, mixed> */
    public function handle(string $from, string $to): array
    {
        $today = CarbonImmutable::now('Europe/Warsaw')->startOfDay();
        $active = Membership::query()->whereIn('order_id', $this->paidOrders()->select('orders.id'))
            ->where('starts_on', '<', $today->addDay()->toDateString())
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $today->toDateString()))
            ->where(fn ($query) => $query->whereNull('entries_remaining')->orWhere('entries_remaining', '>', 0));
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Europe/Warsaw')->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Europe/Warsaw')->addDay()->utc();
        $sales = $this->paidOrders()->where('paid_at', '>=', $start)->where('paid_at', '<', $end);
        // SQL sums stay exact (integer/decimal digits), never converted through floating point.
        $totals = $sales->toBase()->selectRaw('COUNT(*) AS sales_count, COALESCE(SUM(orders.amount_grosz), 0) AS total_grosz')->first();
        $amount = filter_var((string) $totals->total_grosz, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new LogicException('Sales total exceeds the supported integer-grosz range.');
        }
        $recent = Order::query()->with(['payments', 'membership'])->orderByDesc('ordered_at')->orderByDesc('id')->limit(10)->get();

        return ['today' => $today->toDateString(), 'from' => $from, 'to' => $to,
            'activeMemberships' => (clone $active)->count(),
            'expiringMemberships' => $active->whereNotNull('expires_on')->where('expires_on', '<', $today->addDays(8)->toDateString())->count(),
            'salesCount' => (int) $totals->sales_count, 'salesLabel' => PlanPrice::label($amount),
            'recentOrders' => $recent->map(function (Order $order): array {
                $data = $this->summary->make($order, false, true);

                return ['reference' => $order->public_reference, 'planName' => $data['planName'], 'state' => $data['state'],
                    'priceLabel' => $data['priceLabel'], 'orderedAt' => $data['orderedAt'],
                    'showUrl' => route('admin.sales.show', $order->public_reference, absolute: false)];
            }),
        ];
    }

    /** @return Builder<Order> */
    private function paidOrders(): Builder
    {
        return Order::query()->where('orders.status', OrderStatus::Paid)->whereNotNull('orders.paid_at')
            ->whereHas('payments', fn ($payment) => $payment->where('status', PaymentStatus::Confirmed), '=', 1)
            ->whereHas('payments', fn ($payment) => $payment->where('payments.status', PaymentStatus::Confirmed)->whereNotNull('payments.confirmed_at')
                ->whereColumn('payments.amount_grosz', 'orders.amount_grosz')->whereColumn('payments.currency', 'orders.currency')
                ->whereColumn('payments.method', 'orders.payment_method')
                ->where(fn ($context) => $context->where(fn ($online) => $online->where('orders.sales_channel', SalesChannel::Online)
                    ->where('orders.payment_method', PaymentMethod::Przelewy24)->where('payments.provider', PaymentProvider::Przelewy24)
                    ->whereNotNull('orders.accepted_regulation_version_id')->whereNotNull('orders.regulation_accepted_at'))
                    ->orWhere(fn ($receipt) => $receipt->where('orders.sales_channel', SalesChannel::Reception)
                        ->whereIn('orders.payment_method', [PaymentMethod::Cash, PaymentMethod::Card])->where('payments.provider', PaymentProvider::Reception)
                        ->whereNotNull('orders.created_by_user_id')->whereNotNull('payments.confirmed_by_user_id'))));
    }
}
