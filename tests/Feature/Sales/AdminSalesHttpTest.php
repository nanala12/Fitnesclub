<?php

use App\Actions\Documents\RequestDocumentResend;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Memberships\RedeemMembershipEntry;
use App\Actions\Orders\CreateReceptionOrder;
use App\Actions\Payments\ConfirmReceptionPayment;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->entries(2)->create(['is_active' => true, 'revision' => 0]);
});

afterEach(function () {
    DB::table('membership_entries')->delete();
    DB::table('membership_adjustments')->delete();
    DB::table('orders')->update(['renewal_source_membership_id' => null, 'reception_request_id' => null]);
    foreach (DB::table('document_deliveries')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('document_deliveries')->where('id', $id)->delete();
    }
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
});

function adminReceipt(string $method = 'cash', array $attributes = []): Order
{
    $order = app(CreateReceptionOrder::class)->handle(test()->staff, [
        'request_id' => (string) Str::uuid(), 'membership_plan_id' => test()->plan->id, 'plan_revision' => 0, 'payment_method' => $method,
        'for_other_person' => true, 'purchaser' => ['first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'buyer@example.test', 'phone' => '+48600111222'],
        'holder' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => 'holder@example.test', 'phone' => '+48600333444'], ...$attributes,
    ]);
    app(ConfirmReceptionPayment::class)->handle(test()->staff, $order->id);

    return $order->fresh();
}

test('admin sales list filters channel method and order state with paginated safe DTOs', function () {
    $cash = adminReceipt();
    adminReceipt('card');
    Order::factory()->create();
    $this->actingAs($this->admin)->get(route('admin.sales.index'))->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->component('admin/sales/Index')->where('orders.total', 3)
            ->missing('orders.data.0.provider_token')->missing('orders.data.0.holder_email'));
    $this->get(route('admin.sales.index', ['channel' => 'reception', 'method' => 'cash', 'status' => 'paid']))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1)->where('orders.data.0.reference', $cash->public_reference));
    $this->get(route('admin.sales.index', ['channel' => 'online', 'method' => 'card']))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    $this->get(route('admin.sales.index', ['method' => 'wire']))->assertSessionHasErrors('method');
    $this->get(route('admin.sales.index', ['channel' => ['online']]))->assertSessionHasErrors('channel');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('phone search distinguishes purchaser and holder without putting contacts in URLs or merging guests', function () {
    $first = adminReceipt();
    $second = adminReceipt('card');
    $this->actingAs($this->admin)->post(route('admin.sales.search'), ['phone' => '0048 (600) 333-444', 'person' => 'holder'])->assertRedirect(route('admin.sales.index'));
    $this->get(route('admin.sales.index'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 2)->where('search.phone', '+48600333444'));
    $this->post(route('admin.sales.search'), ['phone' => '+48600333444', 'person' => 'purchaser'])->assertRedirect();
    $this->get(route('admin.sales.index'))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    $this->post(route('admin.sales.search'), ['reference' => $second->public_reference, 'person' => 'holder'])->assertRedirect();
    $this->get(route('admin.sales.index'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1)->where('orders.data.0.reference', $second->public_reference));
    expect($first->fresh()->purchaser_user_id)->toBeNull()->and($second->fresh()->purchaser_user_id)->toBeNull();
});

test('sale detail presents preserved terms separate people all payment attempts and receipt actor without provider secrets', function () {
    $receipt = adminReceipt('card');
    $this->actingAs($this->admin)->get(route('admin.sales.show', $receipt->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('holder.name', 'Jan Testowy')->where('purchaser.name', 'Anna Testowa')->where('createdBy', $this->staff->name)
        ->where('payments.0.confirmedBy', $this->staff->name)->where('payments.0.method', 'card')
        ->where('holder.historyUrl', null)->where('purchaser.historyUrl', null)->where('order.state', 'pending_activation'));
    $online = Order::factory()->create();
    Payment::factory()->create(['order_id' => $online->id, 'status' => 'failed', 'provider_token' => 'private-old-token']);
    Payment::factory()->create(['order_id' => $online->id, 'status' => 'pending', 'provider_token' => 'private-new-token', 'provider_session_id' => 'private-session']);
    $this->get(route('admin.sales.show', $online->public_reference))->assertInertia(fn (Assert $page) => $page
        ->has('payments', 2)->where('payments.0.status', 'pending')->where('payments.1.status', 'failed')
        ->missing('payments.0.provider_session_id')->missing('payments.0.provider_token')->missing('payments.0.p24_merchant_id'));
});

test('scoped audit traces sale activation entry and manual resend while excluding other orders and preserving history', function () {
    $order = adminReceipt();
    $member = $order->membership;
    app(ActivateMembership::class)->handle($this->staff, $member->id);
    app(RedeemMembershipEntry::class)->handle($this->staff, $member->id, (string) Str::uuid());
    $delivery = DocumentDelivery::sole();
    $delivery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    app(RequestDocumentResend::class)->handle($this->admin, $delivery->id, (string) Str::uuid());
    $other = adminReceipt();
    $before = AuditLog::count();
    $this->actingAs($this->admin)->get(route('admin.audit.index', ['order' => $order->public_reference]))->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
        ->where('orderReference', $order->public_reference)->where('entries.total', 6)
        ->where('entries.data.0.actor.id', $this->admin->id)->where('entries.data.1.actor.id', $this->staff->id)
        ->where('entries.data.1.changes.0.label', 'Pozostałe wejścia'));
    $this->get(route('admin.deliveries.index', ['order' => $order->public_reference]))->assertInertia(fn (Assert $page) => $page
        ->where('deliveries.total', 2)->where('deliveries.data.0.orderReference', $order->public_reference));
    $this->get(route('admin.deliveries.index', ['order' => $other->public_reference]))->assertInertia(fn (Assert $page) => $page->where('deliveries.total', 1));
    expect(AuditLog::count())->toBe($before)->and($member->fresh()->entries_remaining)->toBe(1);
});

test('account history ignores stale contact search and never imports matching guest orders', function () {
    $account = User::factory()->create();
    adminReceipt('cash', ['purchaser_user_id' => $account->id, 'holder_user_id' => $account->id]);
    adminReceipt('card', ['purchaser_user_id' => $account->id, 'holder_user_id' => $account->id]);
    adminReceipt();
    $this->actingAs($this->admin)->post(route('admin.sales.search'), ['phone' => '+48600999888', 'person' => 'holder'])->assertRedirect();
    $this->get(route('admin.sales.index', ['holder_account' => $account->id]))->assertInertia(fn (Assert $page) => $page->where('orders.total', 2)->where('search', []));
    $this->get(route('admin.sales.index', ['purchaser_account' => $account->id]))->assertInertia(fn (Assert $page) => $page->where('orders.total', 2));
});

test('guest renewal history uses explicit lineage instead of shared phone', function () {
    $first = adminReceipt();
    $source = app(ActivateMembership::class)->handle($this->staff, $first->membership->id);
    adminReceipt('cash', ['renewal_source_membership_id' => $source->id]);
    adminReceipt();
    $this->actingAs($this->admin)->get(route('admin.sales.index', ['root' => $source->id]))->assertInertia(fn (Assert $page) => $page->where('orders.total', 2));
});

test('sales list uses pagination and filtered links retain only validated filters', function () {
    Order::factory()->count(21)->create();
    $this->actingAs($this->admin)->get(route('admin.sales.index', ['channel' => 'online', 'ignored' => 'private@example.test']))->assertInertia(fn (Assert $page) => $page
        ->has('orders.data', 20)->where('orders.total', 21)->where('filters.channel', 'online')->missing('filters.ignored'));
    $this->get(route('admin.sales.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
});

test('non-admin roles cannot read sales scoped audit or delivery history', function (string $role) {
    $order = adminReceipt();
    $actor = match ($role) {
        'employee' => $this->staff, 'suspended' => User::factory()->admin()->suspended()->create(), default => User::factory()->create()
    };
    foreach (['admin.sales.index', 'admin.sales.show', 'admin.audit.index', 'admin.deliveries.index'] as $route) {
        $this->actingAs($actor)->get(route($route, $route === 'admin.sales.show' ? $order->public_reference : ['order' => $order->public_reference]))->assertForbidden();
    }
    $this->actingAs($actor)->post(route('admin.sales.search'), ['person' => 'holder'])->assertForbidden();
})->with(['employee', 'customer', 'suspended']);

test('guests and unverified administrators must authenticate and verify before reading sales', function () {
    $this->get(route('admin.sales.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->admin()->unverified()->create())->get(route('admin.sales.index'))->assertRedirect(route('verification.notice'));
});

test('scoped audit and delivery reject malformed and unknown order references', function (string $route) {
    $this->actingAs($this->admin)->get(route($route, ['order' => 'bad']))->assertSessionHasErrors('order');
    $this->get(route($route, ['order' => (string) Str::ulid()]))->assertNotFound();
})->with(['admin.audit.index', 'admin.deliveries.index']);

test('existing admin session loses sales audit and delivery access after persisted role revocation', function () {
    $this->actingAs($this->admin);
    DB::table('users')->where('id', $this->admin->id)->update(['role' => 'employee']);
    foreach (['admin.sales.index', 'admin.audit.index', 'admin.deliveries.index'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});
