<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false, 'payments.przelewy24.enabled' => false]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 3, 28)->setTime(23, 30));
    $this->owner = User::factory()->create();
});

afterEach(function () {
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

test('history requires login and verification and suspended accounts cannot read purchases', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('orders.index'))->assertRedirect(route('verification.notice'));
    $this->owner->forceFill(['suspended_at' => now()])->save();
    $order = Order::factory()->create(['purchaser_user_id' => $this->owner->id]);
    $this->actingAs($this->owner)->get(route('orders.index'))->assertForbidden();
    $this->get(route('orders.show', $order->public_reference))->assertRedirect(route('login'));
});

test('history includes only explicit purchaser ownership and never claims guest or holder orders', function () {
    $own = Order::factory()->create(['purchaser_user_id' => $this->owner->id]);
    $guest = Order::factory()->create(['purchaser_email' => $this->owner->email, 'purchaser_phone' => $this->owner->phone]);
    $held = Order::factory()->create(['purchaser_user_id' => User::factory(), 'holder_user_id' => $this->owner->id]);
    $this->actingAs($this->owner)->get(route('orders.index'))->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('orders/Index')->where('orders.total', 1)
            ->where('orders.data.0.reference', $own->public_reference));
    foreach ([$guest, $held] as $other) {
        $this->get(route('orders.show', $other->public_reference))->assertNotFound();
    }
    expect($guest->fresh()->purchaser_user_id)->toBeNull();
    Http::assertNothingSent();
});

test('administrator and employee have only their own purchase history', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    Order::factory()->create(['purchaser_user_id' => $actor->id]);
    $foreign = Order::factory()->create(['purchaser_user_id' => $this->owner->id]);
    $this->actingAs($actor)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
    $this->get(route('orders.show', $foreign->public_reference))->assertNotFound();
})->with(['admin', 'employee']);

test('own reception history retains channel method and snapshot without contact or provider leakage', function (string $method) {
    $plan = MembershipPlan::factory()->create(['name' => 'Plan historyczny', 'price_grosz' => 16000]);
    $order = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id, 'purchaser_user_id' => $this->owner->id, 'payment_method' => $method]);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'provider' => PaymentProvider::Reception, 'method' => $method,
        'status' => PaymentStatus::Confirmed, 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    $member = Membership::factory()->create(['order_id' => $order->id]);
    $plan->forceFill(['name' => 'Zmieniony katalog', 'price_grosz' => 99999])->save();
    $this->actingAs($this->owner)->get(route('orders.show', $order->public_reference))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('public/Checkout/Show')->where('order.planName', 'Plan historyczny')
            ->where('order.priceLabel', '160,00 zł')->where('order.salesChannel', 'reception')->where('order.paymentMethod', $method)
            ->where('order.state', 'pending_activation')->where('order.paid', true)->where('order.canPay', false)
            ->where('order.startsOn', null)->where('order.pdfUrl', route('memberships.pdf', $member->id, absolute: false))
            ->missing('order.holder_email')->missing('order.recipient_email')->missing('order.provider_token')->missing('order.purchaser_phone'));
    $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->where('orders.data.0.salesChannel', 'reception')->where('orders.data.0.paymentMethod', $method));
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and($payment->fresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and($member->fresh()->activated_at)->toBeNull();
    Http::assertNothingSent();
})->with(['cash', 'card']);

test('paid detail derives lifecycle from stored dates and Warsaw day without changing activation or balance', function (array $attributes, string $state) {
    $plan = isset($attributes['entries_remaining']) ? MembershipPlan::factory()->entries(8)->create() : MembershipPlan::factory()->create();
    $order = Order::factory()->paidAtReception()->create(['purchaser_user_id' => $this->owner->id, 'membership_plan_id' => $plan->id]);
    if (isset($attributes['starts_on'])) {
        if ($state === 'scheduled' || ($attributes['entries_granted'] ?? null) === 11) {
            $sourceOrder = Order::factory()->paidAtReception()->create(['membership_plan_id' => $plan->id]);
            $source = Membership::factory()->create(['order_id' => $sourceOrder->id, 'starts_on' => '2026-03-01',
                'expires_on' => $plan->kind->value === 'time' ? '2026-03-29' : null,
                'activated_at' => now()->subDays(28), 'activated_by_user_id' => $order->created_by_user_id]);
            $attributes += ['renewed_from_id' => $source->id, 'renewal_root_membership_id' => $source->id];
        } else {
            $attributes += ['activated_at' => now()->subDays(28), 'activated_by_user_id' => $order->created_by_user_id];
        }
    }
    Payment::factory()->create(['order_id' => $order->id, 'provider' => PaymentProvider::Reception, 'method' => PaymentMethod::Cash,
        'status' => PaymentStatus::Confirmed, 'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    $member = Membership::factory()->create(['order_id' => $order->id, ...$attributes]);
    $before = $member->fresh()->getRawOriginal();
    $this->actingAs($this->owner)->get(route('orders.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('order.state', $state)->where('order.paid', true)->where('order.canPay', false));
    expect($member->fresh()->getRawOriginal())->toBe($before);
})->with([
    'first visit' => [[], 'pending_activation'],
    'renewal today' => [['starts_on' => '2026-03-29', 'expires_on' => '2026-04-28', 'entries_remaining' => null], 'active'],
    'future renewal' => [['starts_on' => '2026-03-30', 'expires_on' => '2026-04-29', 'entries_remaining' => null], 'scheduled'],
    'inclusive last day' => [['starts_on' => '2026-02-28', 'expires_on' => '2026-03-29', 'entries_remaining' => null], 'active'],
    'expired in Warsaw' => [['starts_on' => '2026-02-28', 'expires_on' => '2026-03-28', 'entries_remaining' => null], 'expired'],
    'entry renewal' => [['starts_on' => '2026-03-29', 'expires_on' => null, 'entries_remaining' => 11, 'entries_granted' => 11], 'active'],
    'exhausted' => [['starts_on' => '2026-03-28', 'expires_on' => null, 'entries_remaining' => 0], 'exhausted'],
]);

test('paid order missing issued membership does not claim activation or expose a PDF', function () {
    $order = Order::factory()->paidAtReception()->create(['purchaser_user_id' => $this->owner->id]);
    $this->actingAs($this->owner)->get(route('orders.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('order.state', 'checking_payment')->where('order.paid', false)->where('order.pdfUrl', null)->where('order.canPay', false));
    expect(Membership::count())->toBe(0);
});

test('history is paginated newest first with an empty state and rejects invalid pages', function () {
    $this->actingAs($this->owner)->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    Order::factory()->count(16)->create(['purchaser_user_id' => $this->owner->id]);
    $newest = Order::latest('id')->firstOrFail();
    $this->get(route('orders.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 15)->where('orders.total', 16)->where('orders.data.0.reference', $newest->public_reference));
    $this->get(route('orders.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
    $this->get(route('orders.index', ['page' => -1]))->assertSessionHasErrors('page');
});

test('mismatched confirmed payment cannot show a paid result or private PDF', function () {
    $order = Order::factory()->paidAtReception()->create(['purchaser_user_id' => $this->owner->id]);
    Payment::factory()->create(['order_id' => $order->id, 'provider' => PaymentProvider::Reception, 'method' => PaymentMethod::Cash,
        'amount_grosz' => $order->amount_grosz + 1, 'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(), 'confirmed_by_user_id' => $order->created_by_user_id]);
    Membership::factory()->create(['order_id' => $order->id]);
    $this->actingAs($this->owner)->get(route('orders.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('order.state', 'checking_payment')->where('order.paid', false)->where('order.pdfUrl', null)->where('order.canPay', false));
    Http::assertNothingSent();
});
