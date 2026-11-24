<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutAccess
{
    public function issue(Request $request): string
    {
        $grants = array_filter($request->session()->get('checkout_requests', []), fn (array $grant): bool => $grant['issued_at'] > now()->subHours(2)->timestamp);
        $id = (string) Str::uuid();
        $grants[$id] = ['user_id' => $request->user()?->id, 'issued_at' => now()->timestamp];
        $request->session()->put('checkout_requests', array_slice($grants, -20, null, true));

        return $id;
    }

    public function requireRequest(Request $request, string $id): void
    {
        $grant = $request->session()->get('checkout_requests.'.$id);
        abort_unless(is_array($grant) && ($grant['issued_at'] ?? 0) > now()->subHours(2)->timestamp
            && ($grant['user_id'] ?? null) === $request->user()?->id, 404);
    }

    public function authorize(Request $request, Order $order): void
    {
        if ($order->purchaser_user_id !== null) {
            abort_unless($request->user()?->id === $order->purchaser_user_id, 404);

            return;
        }
        $grant = $request->session()->get('checkout_requests.'.($order->checkout_request_id ?? 'missing'));
        abort_unless(is_array($grant) && ($grant['issued_at'] ?? 0) > now()->subHours(2)->timestamp
            && ($grant['user_id'] ?? null) === null, 404);
    }
}
