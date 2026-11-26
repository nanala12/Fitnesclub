<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\GetMembershipEntryHistory;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Orders\CreateReceptionOrder;
use App\Actions\Payments\ConfirmReceptionPayment;
use App\Enums\OrderStatus;
use App\Enums\SalesChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\MembershipOperationRequest;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderSummary;
use App\Support\PhoneNumber;
use App\Support\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MembershipController extends Controller
{
    public function index(Request $request, OrderSummary $summary): Response
    {
        $this->authorizeStaff($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $phone = $request->session()->get('staff_holder_phone');
        $orders = Order::query()->when($phone === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($phone !== null, fn ($query) => $query->where('holder_phone', $phone))
            ->with(['payments', 'membership'])->orderByDesc('id')->paginate(15);

        return $this->page($request, 'staff/memberships/Index', ['phone' => $phone,
            'accounts' => $phone === null ? [] : User::query()->where('phone', $phone)->get(['id', 'name'])->map(fn ($user): array => [
                'id' => $user->id, 'name' => $user->name, 'saleUrl' => route('staff.memberships.create', ['account_id' => $user->id], absolute: false),
            ]),
            'results' => $orders->through(fn (Order $order): array => ['holderName' => $order->holder_first_name.' '.$order->holder_last_name,
                ...$this->purchase($order, $summary)]),
        ]);
    }

    public function search(Request $request): RedirectResponse
    {
        $this->authorizeStaff($request);
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }
        $data = $request->validate(['phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D']],
            ['phone.regex' => 'Podaj pełny numer z prefiksem kraju, np. +48 600 111 222.']);
        $request->session()->put('staff_holder_phone', $data['phone'] ?? null);

        return to_route('staff.memberships.index');
    }

    public function show(string $reference, Request $request, OrderSummary $summary, GetMembershipEntryHistory $history): Response
    {
        $this->authorizeStaff($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $order = Order::query()->with(['payments', 'membership.activatedBy:id,name'])->where('public_reference', $reference)->firstOrFail();
        $purchase = $this->purchase($order, $summary);
        $member = $order->membership;
        $entries = $member === null ? null : $history->handle($request->user(), $member->id, (int) $request->input('page', 1));

        return $this->page($request, 'staff/memberships/Show', [
            'order' => $purchase,
            'holder' => ['name' => $order->holder_first_name.' '.$order->holder_last_name, 'phone' => $order->holder_phone, 'email' => $order->holder_email],
            'canConfirmPayment' => $order->sales_channel === SalesChannel::Reception && $order->status === OrderStatus::Pending,
            'membershipId' => $member?->id,
            'canActivate' => $purchase['paid'] && $purchase['state'] === 'pending_activation' && $member?->renewed_from_id === null,
            'canRedeem' => $purchase['paid'] && $purchase['state'] === 'active' && ($member->entries_remaining ?? 0) > 0,
            'activatedAt' => $member?->activated_at?->toISOString(), 'activatedBy' => $member?->activatedBy?->name,
            'entries' => $entries?->through(fn ($entry): array => ['id' => $entry->id, 'usedAt' => $entry->used_at->toISOString(),
                'actor' => $entry->recordedBy->name]),
        ]);
    }

    public function activate(int $membership, MembershipOperationRequest $request, ActivateMembership $activate): RedirectResponse
    {
        $activate->handle($request->user(), $membership);

        return $this->backToMember($membership, 'Karnet został aktywowany. Nie odliczono wejścia.');
    }

    public function redeem(int $membership, MembershipOperationRequest $request, RedeemMembershipEntry $redeem): RedirectResponse
    {
        $redeem->handle($request->user(), $membership, $request->validated('request_id'));

        return $this->backToMember($membership, 'Zapisano wykorzystanie jednego wejścia.');
    }

    public function create(Request $request): Response
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['account_id' => ['sometimes', 'integer', 'min:1']]);
        $account = isset($data['account_id']) ? User::query()->findOrFail((int) $data['account_id']) : null;
        $plans = MembershipPlan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return $this->page($request, 'staff/memberships/Create', ['requestId' => (string) Str::uuid(),
            'account' => $account === null ? null : ['id' => $account->id, 'name' => $account->name,
                'first_name' => $account->first_name ?? '', 'last_name' => $account->last_name ?? '', 'email' => $account->email, 'phone' => $account->phone],
            'plans' => $plans->map(fn ($plan): array => ['id' => $plan->id, 'revision' => $plan->revision, 'name' => $plan->name,
                'priceLabel' => PlanPrice::label($plan->price_grosz), 'kind' => $plan->kind->value,
                'includedEntries' => $plan->included_entries, 'durationMonths' => $plan->duration_months]),
        ]);
    }

    public function store(Request $request, CreateReceptionOrder $create): RedirectResponse
    {
        $this->authorizeStaff($request);
        // Account association is an explicit choice for this new purchase, never contact-based claiming.
        $request->validate(['holder_user_id' => ['prohibited'], 'renewal_source_membership_id' => ['prohibited']]);
        $order = $create->handle($request->user(), $request->all());

        return to_route('staff.memberships.show', $order->public_reference)->with('success', 'Zapisano zamówienie. Potwierdź opłatę dopiero po jej otrzymaniu.');
    }

    public function confirmPayment(string $reference, MembershipOperationRequest $request, ConfirmReceptionPayment $confirm): RedirectResponse
    {
        $order = Order::query()->where('public_reference', $reference)->firstOrFail();
        $confirm->handle($request->user(), $order->id);

        return to_route('staff.memberships.show', $order->public_reference)->with('success', 'Potwierdzono opłatę. Karnet czeka na aktywację przy pierwszej wizycie.');
    }

    private function authorizeStaff(Request $request): void
    {
        Gate::forUser($request->user()->fresh())->authorize('issue', Membership::class);
    }

    /** @return array<string, mixed> */
    private function purchase(Order $order, OrderSummary $summary): array
    {
        $data = $summary->make($order, false, true);
        $data['showUrl'] = route('staff.memberships.show', $order->public_reference, absolute: false);

        return $data;
    }

    private function backToMember(int $membership, string $message): RedirectResponse
    {
        $member = Membership::query()->with('order')->findOrFail($membership);

        return to_route('staff.memberships.show', $member->order->public_reference)->with('success', $message);
    }

    /** @param array<string, mixed> $props */
    private function page(Request $request, string $component, array $props): Response
    {
        Inertia::encryptHistory();
        $response = Inertia::render($component, $props)->toResponse($request);
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }
}
