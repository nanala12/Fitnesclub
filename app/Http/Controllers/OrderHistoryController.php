<?php

namespace App\Http\Controllers;

use App\Actions\PublicSite\PublicSiteContent;
use App\Models\Order;
use App\Services\Payments\Przelewy24Configuration;
use App\Support\OrderSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class OrderHistoryController extends Controller
{
    public function index(Request $request, OrderSummary $summary): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $orders = Order::query()->where('purchaser_user_id', $request->user()->id)
            ->with(['payments', 'membership'])->orderByDesc('ordered_at')->orderByDesc('id')->paginate(15);
        $available = $this->available();
        Inertia::encryptHistory();

        $response = Inertia::render('orders/Index', [
            'orders' => $orders->through(fn (Order $order): array => $summary->make($order, $available, true)),
        ])->toResponse($request);
        $response->headers->add($this->privateHeaders());

        return $response;
    }

    public function show(string $reference, Request $request, OrderSummary $summary, PublicSiteContent $content): Response
    {
        $order = Order::query()->where('public_reference', $reference)->where('purchaser_user_id', $request->user()->id)->firstOrFail();
        abort_unless(Gate::forUser($request->user()->fresh())->allows('view', $order), 404);
        Inertia::encryptHistory();

        $response = Inertia::render('public/Checkout/Show', [...$content->shared('checkout'),
            'order' => $summary->make($order, $this->available(), true), 'notice' => $request->session()->get('checkout_notice'),
        ])->toResponse($request);
        $response->headers->add($this->privateHeaders());

        return $response;
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
