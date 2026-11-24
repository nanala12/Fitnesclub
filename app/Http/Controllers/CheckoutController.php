<?php

namespace App\Http\Controllers;

use App\Actions\Orders\CreateOnlineOrder;
use App\Actions\Payments\InitiatePrzelewy24Payment;
use App\Actions\PublicSite\PublicSiteContent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Exceptions\PaymentRegistrationException;
use App\Http\Requests\CheckoutRequest;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Services\Payments\Przelewy24Client;
use App\Services\Payments\Przelewy24Configuration;
use App\Support\CheckoutAccess;
use App\Support\PlanPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CheckoutController extends Controller
{
    public function __construct(private CheckoutAccess $access) {}

    public function create(Request $request, PublicSiteContent $content): HttpResponse
    {
        $data = $request->validate(['plan' => ['sometimes', 'integer', 'min:1']]);
        $plans = MembershipPlan::query()->publiclyAvailable()->orderBy('sort_order')->orderBy('id')->get();
        $selected = isset($data['plan']) ? (int) $data['plan'] : $plans->first()?->id;
        abort_if($selected !== null && ! $plans->contains('id', $selected), 404);
        $regulation = $content->publishedRegulations()->first();
        $user = $request->user();
        if ($user === null) {
            $request->session()->put('url.intended', route('checkout.create', $selected === null ? [] : ['plan' => $selected]));
        }
        Inertia::encryptHistory();

        $response = Inertia::render('public/Checkout/Create', [...$content->shared('checkout'),
            'plans' => $plans->map(fn (MembershipPlan $plan): array => [
                'id' => $plan->id, 'revision' => $plan->revision, 'name' => $plan->name, 'description' => $plan->description,
                'kind' => $plan->kind->value, 'priceLabel' => PlanPrice::label($plan->price_grosz),
                'durationMonths' => $plan->duration_months, 'includedEntries' => $plan->included_entries,
            ]), 'selectedPlanId' => $selected, 'checkoutRequestId' => $this->access->issue($request),
            'regulation' => $regulation === null ? null : ['id' => $regulation->id, 'versionNumber' => $regulation->version_number],
            'paymentAvailable' => $this->available(),
            'purchaser' => ['first_name' => $user->first_name ?? '', 'last_name' => $user->last_name ?? '', 'email' => $user->email ?? '', 'phone' => $user->phone ?? ''],
        ])->toResponse($request);
        $response->headers->add($this->privateHeaders());

        return $response;
    }

    public function store(CheckoutRequest $request, CreateOnlineOrder $orders): HttpResponse
    {
        $id = $request->validated('checkout_request_id');
        $this->access->requireRequest($request, $id);
        Przelewy24Configuration::load()->returnUrlFor(str_repeat('0', 26));
        $order = $orders->handle($request->user(), $request->except('checkout_request_id'), $id);

        return $this->startPayment($order, $request);
    }

    public function show(string $reference, Request $request, PublicSiteContent $content): HttpResponse
    {
        $order = $this->find($reference, $request);
        $payment = $order->payments()->latest('id')->first();
        $paid = $order->status === OrderStatus::Paid;
        $uncertain = in_array($payment?->status, [PaymentStatus::Unknown, PaymentStatus::Initiated], true);
        $retry = $order->status === OrderStatus::Pending && ! $uncertain
            && ($payment === null || in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true));
        Inertia::encryptHistory();

        $response = Inertia::render('public/Checkout/Show', [...$content->shared('checkout'), 'order' => [
            'reference' => $order->public_reference, 'planName' => $order->plan_snapshot['name'], 'priceLabel' => PlanPrice::label($order->amount_grosz),
            'state' => $paid ? 'pending_activation' : ($uncertain ? 'checking_payment' : ($order->status === OrderStatus::Pending ? 'awaiting_payment' : 'stopped')),
            'canPay' => $retry && $this->available(), 'paymentAvailable' => $this->available(),
            'isGuest' => $order->purchaser_user_id === null,
            'pdfUrl' => $paid && $order->membership()->exists() ? route('checkout.pdf', $order->public_reference, absolute: false) : null,
        ], 'notice' => $request->session()->get('checkout_notice')])->toResponse($request);
        $response->headers->add($this->privateHeaders());

        return $response;
    }

    public function pay(string $reference, Request $request): HttpResponse
    {
        return $this->startPayment($this->find($reference, $request), $request);
    }

    public function paymentReturn(Request $request): RedirectResponse
    {
        $data = $request->validate(['zamowienie' => ['required', 'ulid']]);
        $order = $this->find($data['zamowienie'], $request);

        // Browser input never triggers provider verification or a paid transition.
        return redirect()->route('checkout.show', $order->public_reference)->withHeaders($this->privateHeaders());
    }

    private function find(string $reference, Request $request): Order
    {
        $order = Order::query()->where('public_reference', $reference)->where('sales_channel', SalesChannel::Online)->firstOrFail();
        $this->access->authorize($request, $order);

        return $order;
    }

    private function startPayment(Order $order, Request $request): HttpResponse
    {
        if ($order->status !== OrderStatus::Pending) {
            return redirect()->route('checkout.show', $order->public_reference);
        }
        try {
            // Null is safe only after current checkout creation or scoped guest access above.
            $actor = $order->purchaser_user_id === null ? null : $request->user();
            $payment = app(InitiatePrzelewy24Payment::class)->handle($order->id, $actor);

            $response = Inertia::location(app(Przelewy24Client::class)->redirectUrl($payment));
            $response->headers->add($this->privateHeaders());

            return $response;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
        } catch (PaymentRegistrationException) {
            $message = 'Przygotowanie płatności wymaga sprawdzenia. Odśwież stan zamówienia za chwilę.';
        }

        return redirect()->route('checkout.show', $order->public_reference)
            ->with('checkout_notice', $message)->withHeaders($this->privateHeaders());
    }

    private function available(): bool
    {
        try {
            Przelewy24Configuration::load()->returnUrlFor(str_repeat('0', 26));

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
