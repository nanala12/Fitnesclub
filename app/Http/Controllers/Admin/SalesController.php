<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderSummary;
use App\Support\PhoneNumber;
use App\Support\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SalesController extends Controller
{
    public function index(Request $request, OrderSummary $summary): Response
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate(['channel' => ['nullable', Rule::in(['online', 'reception'])],
            'method' => ['nullable', Rule::in(['przelewy24', 'cash', 'card'])], 'status' => ['nullable', Rule::in(['pending', 'paid', 'failed', 'cancelled'])],
            'holder_account' => ['nullable', 'integer', 'min:1'], 'purchaser_account' => ['nullable', 'integer', 'min:1'],
            'root' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $search = ! empty($filters['holder_account']) || ! empty($filters['purchaser_account']) || ! empty($filters['root']) ? [] : $request->session()->get('admin_sales_search', []);
        $orders = Order::query()->with(['payments', 'membership', 'createdBy:id,name'])
            ->when($filters['channel'] ?? null, fn ($query, $value) => $query->where('sales_channel', $value))
            ->when($filters['method'] ?? null, fn ($query, $value) => $query->where('payment_method', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['holder_account'] ?? null, fn ($query, $value) => $query->where('holder_user_id', $value))
            ->when($filters['purchaser_account'] ?? null, fn ($query, $value) => $query->where('purchaser_user_id', $value))
            ->when($filters['root'] ?? null, fn ($query, $value) => $query->whereHas('membership', fn ($members) => $members->where('id', $value)->orWhere('renewal_root_membership_id', $value)))
            ->when($search['reference'] ?? null, fn ($query, $value) => $query->where('public_reference', $value))
            ->when($search['phone'] ?? null, fn ($query, $value) => $query->where(($search['person'] ?? 'holder') === 'purchaser' ? 'purchaser_phone' : 'holder_phone', $value))
            ->orderByDesc('ordered_at')->orderByDesc('id')->paginate(20)->appends($filters);

        return $this->page($request, 'admin/sales/Index', ['filters' => $filters, 'search' => $search,
            'orders' => $orders->through(fn (Order $order): array => [...$this->purchase($order, $summary),
                'holderName' => $order->holder_first_name.' '.$order->holder_last_name,
                'purchaserName' => $order->getAttribute('purchaser_first_name').' '.$order->getAttribute('purchaser_last_name'),
            ]),
        ]);
    }

    public function search(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }
        $search = $request->validate(['phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'reference' => ['nullable', 'ulid'], 'person' => ['required', Rule::in(['holder', 'purchaser'])]]);
        $request->session()->put('admin_sales_search', $search);

        return to_route('admin.sales.index');
    }

    public function show(string $reference, Request $request, OrderSummary $summary): Response
    {
        $this->authorizeAdmin($request);
        $order = Order::query()->with(['payments.confirmedBy:id,name', 'membership', 'createdBy:id,name'])->where('public_reference', $reference)->firstOrFail();
        $member = $order->membership;
        $root = $member === null ? null : ($member->renewal_root_membership_id ?? $member->id);

        return $this->page($request, 'admin/sales/Show', ['order' => $this->purchase($order, $summary),
            'holder' => ['name' => $order->holder_first_name.' '.$order->holder_last_name, 'phone' => $order->holder_phone, 'email' => $order->holder_email,
                'historyUrl' => $order->holder_user_id === null ? null : route('admin.sales.index', ['holder_account' => $order->holder_user_id], absolute: false)],
            'purchaser' => ['name' => $order->getAttribute('purchaser_first_name').' '.$order->getAttribute('purchaser_last_name'),
                'phone' => $order->purchaser_phone, 'email' => $order->purchaser_email,
                'historyUrl' => $order->purchaser_user_id === null ? null : route('admin.sales.index', ['purchaser_account' => $order->purchaser_user_id], absolute: false)],
            'createdBy' => $order->createdBy?->name,
            'payments' => $order->payments->sortByDesc('id')->values()->map(fn ($payment): array => [
                'id' => $payment->id, 'status' => $payment->status->value, 'method' => $payment->method->value,
                'amountLabel' => PlanPrice::label($payment->amount_grosz), 'confirmedAt' => $payment->confirmed_at?->toISOString(),
                'confirmedBy' => $payment->confirmedBy?->name,
            ]),
            'auditUrl' => route('admin.audit.index', ['order' => $order->public_reference], absolute: false),
            'deliveriesUrl' => route('admin.deliveries.index', ['order' => $order->public_reference], absolute: false),
            'receptionUrl' => route('staff.memberships.show', $order->public_reference, absolute: false),
            'renewalHistoryUrl' => $root === null ? null : route('admin.sales.index', ['root' => $root], absolute: false),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        Gate::forUser($request->user()->fresh())->authorize('viewSales', Order::class);
    }

    /** @return array<string, mixed> */
    private function purchase(Order $order, OrderSummary $summary): array
    {
        $data = $summary->make($order, false, true);
        $data['showUrl'] = route('admin.sales.show', $order->public_reference, absolute: false);

        return $data;
    }

    /** @param array<string, mixed> $props */
    private function page(Request $request, string $name, array $props): Response
    {
        Inertia::encryptHistory();
        $response = Inertia::render($name, $props)->toResponse($request);
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }
}
