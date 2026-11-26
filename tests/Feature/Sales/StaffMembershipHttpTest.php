<?php

use App\Actions\Payments\ConfirmReceptionPayment;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipEntry;
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
    Queue::fake();
    Mail::fake();
    Http::preventStrayRequests();
    $this->staff = User::factory()->employee()->create();
    $this->plan = MembershipPlan::factory()->entries(2)->create(['price_grosz' => 8000, 'is_active' => true, 'revision' => 0]);
});

afterEach(function () {
    DB::table('membership_entries')->delete();
    DB::table('document_deliveries')->delete();
    foreach (DB::table('memberships')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
    DB::table('orders')->update(['reception_request_id' => null]);
});

function staffSaleInput(): array
{
    return ['request_id' => (string) Str::uuid(), 'membership_plan_id' => test()->plan->id, 'plan_revision' => test()->plan->revision,
        'payment_method' => 'cash', 'for_other_person' => false,
        'purchaser' => ['first_name' => 'Anna', 'last_name' => 'Testowa', 'phone' => '+48 600 111 222', 'email' => 'buyer@example.test']];
}

function staffOperation(): array
{
    return ['confirmed' => true, 'request_id' => (string) Str::uuid()];
}

test('staff search normalizes phone and lists shared-phone holders separately without an unfiltered directory', function () {
    $first = Order::factory()->create(['holder_first_name' => 'Anna', 'holder_phone' => '+48600111222']);
    $second = Order::factory()->create(['holder_first_name' => 'Jan', 'holder_phone' => '+48600111222']);
    Order::factory()->create(['holder_phone' => '+48600999888']);
    $this->actingAs($this->staff)->get(route('staff.memberships.index'))->assertInertia(fn (Assert $page) => $page->has('results.data', 0));
    $this->post(route('staff.memberships.search'), ['phone' => '0048 (600) 111-222'])->assertRedirect(route('staff.memberships.index'));
    $this->get(route('staff.memberships.index'))->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->where('phone', '+48600111222')->where('results.total', 2)
            ->where('results.data.0.reference', $second->public_reference)->where('results.data.0.holderName', 'Jan Testowy')
            ->where('results.data.1.reference', $first->public_reference)->missing('results.data.0.holder_email')->missing('results.data.0.purchaser_email'));
    $this->post(route('staff.memberships.search'), ['phone' => ''])->assertRedirect();
    $this->get(route('staff.memberships.index'))->assertInertia(fn (Assert $page) => $page->has('results.data', 0));
    Http::assertNothingSent();
});

test('guest reception sale payment activation and entry recording are distinct confirmed idempotent operations', function (string $method) {
    $input = [...staffSaleInput(), 'payment_method' => $method];
    $users = User::count();
    $this->actingAs($this->staff)->post(route('staff.memberships.store'), $input)->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::sole();
    $this->post(route('staff.memberships.store'), $input)->assertRedirect(route('staff.memberships.show', $order->public_reference));
    expect(Order::count())->toBe(1)->and(Membership::count())->toBe(0)->and(Payment::count())->toBe(0)->and(User::count())->toBe($users);
    $this->get(route('staff.memberships.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('canConfirmPayment', true)->where('canActivate', false)->where('canRedeem', false)->where('order.state', 'awaiting_reception'));
    $this->post(route('staff.memberships.confirmPayment', $order->public_reference), ['request_id' => (string) Str::uuid()])->assertSessionHasErrors('confirmed');
    expect(Payment::count())->toBe(0);
    $this->post(route('staff.memberships.confirmPayment', $order->public_reference), staffOperation())->assertRedirect();
    $member = Membership::sole();
    expect($member->starts_on)->toBeNull()->and($member->entries_remaining)->toBe(2)->and(Payment::sole()->confirmed_by_user_id)->toBe($this->staff->id)
        ->and($order->fresh()->created_by_user_id)->toBe($this->staff->id)->and(DocumentDelivery::count())->toBe(1);
    $this->post(route('staff.memberships.confirmPayment', $order->public_reference), staffOperation())->assertRedirect();
    expect(Payment::count())->toBe(1)->and(Membership::count())->toBe(1);
    $this->post(route('staff.memberships.redeem', $member->id), staffOperation())->assertSessionHasErrors('membership');
    $this->post(route('staff.memberships.activate', $member->id), staffOperation())->assertRedirect();
    $activated = $member->fresh()->activated_at->toISOString();
    $this->post(route('staff.memberships.activate', $member->id), staffOperation())->assertRedirect();
    expect($member->fresh()->activated_at->toISOString())->toBe($activated)->and($member->fresh()->entries_remaining)->toBe(2)->and(MembershipEntry::count())->toBe(0);
    $this->get(route('staff.memberships.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page->where('canActivate', false)->where('canRedeem', true)->where('order.expiresOn', null));
    $operation = staffOperation();
    $this->post(route('staff.memberships.redeem', $member->id), $operation)->assertRedirect();
    $this->post(route('staff.memberships.redeem', $member->id), $operation)->assertRedirect();
    expect($member->fresh()->entries_remaining)->toBe(1)->and(MembershipEntry::count())->toBe(1);
    $this->get(route('staff.memberships.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.actor', $this->staff->name));
    $this->post(route('staff.memberships.redeem', $member->id), staffOperation())->assertRedirect();
    $this->post(route('staff.memberships.redeem', $member->id), staffOperation())->assertSessionHasErrors('membership');
    expect($member->fresh()->entries_remaining)->toBe(0)->and(MembershipEntry::count())->toBe(2);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['cash', 'card']);

test('another-person receipt preserves separate buyer holder and recipient without creating accounts', function () {
    $input = [...staffSaleInput(), 'for_other_person' => true,
        'holder' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'phone' => '+48600333444', 'email' => 'holder@example.test']];
    $this->actingAs($this->staff)->post(route('staff.memberships.store'), $input)->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::sole();
    $this->post(route('staff.memberships.confirmPayment', $order->public_reference), staffOperation())->assertRedirect();
    expect($order->purchaser_email)->toBe('buyer@example.test')->and($order->recipient_email)->toBe('holder@example.test')
        ->and($order->purchaser_user_id)->toBeNull()->and(Membership::sole()->holder_user_id)->toBeNull();
});

test('unpaid online orders cannot be activated redeemed or marked as receipt payment', function () {
    $order = Order::factory()->create();
    $member = Membership::factory()->create(['order_id' => $order->id]);
    $this->actingAs($this->staff)->get(route('staff.memberships.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('canConfirmPayment', false)->where('canActivate', false)->where('canRedeem', false)->where('order.pdfUrl', null));
    $this->post(route('staff.memberships.confirmPayment', $order->public_reference), staffOperation())->assertSessionHasErrors('order');
    $this->post(route('staff.memberships.activate', $member->id), staffOperation())->assertSessionHasErrors('payment');
    $this->post(route('staff.memberships.redeem', $member->id), staffOperation())->assertSessionHasErrors('payment');
    expect(Payment::count())->toBe(0)->and($member->fresh()->starts_on)->toBeNull();
});

test('customers guests unverified and suspended users cannot read or mutate staff resources', function (string $kind) {
    $order = Order::factory()->paidAtReception()->create();
    $member = Membership::factory()->create(['order_id' => $order->id]);
    $actor = match ($kind) {
        'guest' => null, 'unverified' => User::factory()->employee()->unverified()->create(),
        'suspended' => User::factory()->employee()->suspended()->create(), default => User::factory()->create(),
    };
    if ($actor !== null) {
        $this->actingAs($actor);
    }
    foreach ([['GET', route('staff.memberships.index')], ['GET', route('staff.memberships.show', $order->public_reference)],
        ['GET', route('staff.memberships.create')], ['POST', route('staff.memberships.store')], ['POST', route('staff.memberships.search')],
        ['POST', route('staff.memberships.confirmPayment', $order->public_reference)], ['POST', route('staff.memberships.activate', $member->id)], ['POST', route('staff.memberships.redeem', $member->id)]] as [$method, $url]) {
        if ($kind === 'suspended') {
            $this->actingAs($actor);
        }
        $response = $this->call($method, $url, staffOperation());
        if (in_array($kind, ['guest', 'unverified'], true)) {
            $response->assertRedirect();
        } else {
            $response->assertForbidden();
        }
    }
    expect(MembershipEntry::count())->toBe(0)->and(Payment::count())->toBe(0);
})->with(['guest', 'customer', 'unverified', 'suspended']);

test('receipt rejects forged financial fields account linking and stale catalog revision', function (array $overrides, string $error) {
    $this->actingAs($this->staff)->post(route('staff.memberships.store'), [...staffSaleInput(), ...$overrides])->assertSessionHasErrors($error);
    expect(Order::count())->toBe(0);
})->with([[['price' => 1], 'price'], [['status' => 'paid'], 'status'], [['holder_user_id' => 1], 'holder_user_id'], [['plan_revision' => 99], 'plan_revision']]);

test('search rejects malformed or incomplete phone without listing holders', function (mixed $phone) {
    $this->actingAs($this->staff)->post(route('staff.memberships.search'), ['phone' => $phone])->assertSessionHasErrors('phone');
})->with(['600111222', 'abc', ['array']]);

test('staff mutations retain CSRF protection', function () {
    $this->actingAs($this->staff);
    $this->app['env'] = 'local';
    try {
        $this->post(route('staff.memberships.store'), staffSaleInput())->assertStatus(419);
    } finally {
        $this->app['env'] = 'testing';
    }
    expect(Order::count())->toBe(0);
});

test('registered account is selected explicitly for a new reception sale while old guest purchases remain separate', function () {
    $account = User::factory()->create(['phone' => '+48600111222', 'first_name' => 'Alicja', 'last_name' => 'Testowa']);
    $guest = Order::factory()->create(['holder_phone' => $account->phone, 'purchaser_email' => $account->email]);
    $this->actingAs($this->staff)->post(route('staff.memberships.search'), ['phone' => $account->phone])->assertRedirect();
    $this->get(route('staff.memberships.index'))->assertInertia(fn (Assert $page) => $page->where('accounts.0.id', $account->id));
    $this->get(route('staff.memberships.create', ['account_id' => $account->id]))->assertInertia(fn (Assert $page) => $page->where('account.email', $account->email));
    $this->post(route('staff.memberships.store'), [...staffSaleInput(), 'purchaser_user_id' => $account->id])->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::latest('id')->firstOrFail();
    expect($order->purchaser_user_id)->toBe($account->id)->and($order->holder_user_id)->toBe($account->id)->and($guest->fresh()->purchaser_user_id)->toBeNull();
});

test('expired membership history retains employee identity and cannot record a new entry', function () {
    $order = Order::factory()->paidAtReception()->create(['recipient_email' => 'holder@example.test', 'status' => 'pending', 'paid_at' => null]);
    $member = app(ConfirmReceptionPayment::class)->handle($this->staff, $order->id);
    $member->forceFill(['starts_on' => now()->subMonths(2)->toDateString(), 'expires_on' => now()->subMonth()->toDateString(),
        'activated_at' => now()->subMonths(2), 'activated_by_user_id' => $this->staff->id])->save();
    MembershipEntry::factory()->create(['membership_id' => $member->id, 'recorded_by_user_id' => $this->staff->id]);
    $this->actingAs($this->staff)->get(route('staff.memberships.show', $order->public_reference))->assertInertia(fn (Assert $page) => $page
        ->where('order.state', 'expired')->where('canActivate', false)->where('canRedeem', false)->where('entries.total', 1)->where('entries.data.0.actor', $this->staff->name));
    $this->post(route('staff.memberships.redeem', $member->id), staffOperation())->assertSessionHasErrors('membership');
});
