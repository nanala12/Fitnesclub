<?php

use App\Actions\Orders\CreateOnlineOrder;
use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PriceListCategory;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Services\Payments\Przelewy24Notification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false,
        'merchant_id' => 123, 'pos_id' => 456, 'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/platnosc/powrot', 'status_url' => 'https://merchant.example.test/payments/przelewy24/notification']]);
    $this->plan = MembershipPlan::factory()->entries(8)->create(['is_active' => true, 'is_public' => true, 'price_grosz' => 12050]);
    $this->regulation = new RegulationVersion;
    $this->regulation->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now()->subMinute(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $this->buyer = ['first_name' => 'Ania', 'last_name' => 'Testowa', 'email' => 'buyer@example.test', 'phone' => '+48600111222'];
    $this->holder = ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => 'holder@example.test', 'phone' => '+48600333444'];
});

afterEach(function () {
    DB::table('orders')->update(['checkout_request_id' => null]);
    DB::table('payments')->delete();
});

function checkoutHttpInput(): array
{
    test()->get(route('checkout.create', ['plan' => test()->plan->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/Checkout/Create')->where('checkoutRequestId', fn (string $value): bool => Str::isUuid($value)));
    $requestId = array_key_last(session('checkout_requests'));

    return ['checkout_request_id' => $requestId, 'membership_plan_id' => test()->plan->id, 'plan_revision' => 0,
        'for_other_person' => false, 'purchaser' => test()->buyer, 'accept_regulations' => true,
        'accepted_regulation_version_id' => test()->regulation->id];
}

test('guest form contains only public plans current regulation and no seeded contact or secrets', function () {
    MembershipPlan::factory()->create(['is_active' => false, 'name' => 'Hidden plan']);
    $this->get(route('checkout.create'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertInertia(fn (Assert $page) => $page
        ->component('public/Checkout/Create')->has('plans', 1)->where('plans.0.id', $this->plan->id)
        ->where('plans.0.priceLabel', '120,50 zł')->where('plans.0.revision', 0)->where('regulation.id', $this->regulation->id)
        ->where('purchaser.email', '')->where('paymentAvailable', true)->missing('plans.0.terms'));
    Http::assertNothingSent();
});

test('guest checkout redirects through Inertia without creating an account or issuing an unpaid pass', function (bool $other) {
    $input = checkoutHttpInput();
    if ($other) {
        $input['for_other_person'] = true;
        $input['holder'] = $this->holder;
    }
    $users = User::count();
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe(0)->and($request['amount'])->toBe(12050)
            ->and($request['email'])->toBe('buyer@example.test')->and($request['urlReturn'])->toBe('https://merchant.example.test/platnosc/powrot?zamowienie='.Order::sole()->public_reference);

        return Http::response(['data' => ['token' => 'checkout-token'], 'responseCode' => 0]);
    });
    $this->withHeader('X-Inertia', 'true')->post(route('checkout.store'), $input)->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://sandbox.przelewy24.pl/trnRequest/checkout-token');
    $order = Order::sole();
    expect($order->purchaser_user_id)->toBeNull()->and($order->holder_user_id)->toBeNull()->and($order->checkout_request_id)->toBe($input['checkout_request_id'])
        ->and($order->recipient_email)->toBe($other ? 'holder@example.test' : 'buyer@example.test')
        ->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)->and(User::count())->toBe($users);
    $this->withHeaders(['X-Inertia' => ''])->get(route('checkout.show', $order->public_reference))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.state', 'awaiting_payment')->where('order.canPay', true)
            ->missing('order.holder_email')->missing('order.purchaser_phone')->missing('order.provider_session_id'));
})->with([false, true]);

test('account checkout prefills current profile and links only its own holder', function () {
    $account = User::factory()->create(['first_name' => 'Alicja', 'last_name' => 'Testowa', 'email' => 'account@example.test']);
    $this->actingAs($account)->get(route('checkout.create'))->assertInertia(fn (Assert $page) => $page->where('purchaser.first_name', 'Alicja')->where('purchaser.email', $account->email));
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::response(['data' => ['token' => 'token'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    expect(Order::sole()->purchaser_user_id)->toBe($account->id)->and(Order::sole()->holder_user_id)->toBe($account->id)
        ->and($account->fresh()->email)->toBe('account@example.test');
});

test('repeated POST retry and catalog editing preserve one order and one gateway registration', function () {
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::response(['data' => ['token' => 'token'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $this->plan->forceFill(['price_grosz' => 90000, 'revision' => 1, 'is_public' => false])->save();
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $this->post(route('checkout.pay', Order::sole()->public_reference))->assertRedirect();
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)->and(Order::sole()->amount_grosz)->toBe(12050);
    Http::assertSentCount(1);
    $this->post(route('checkout.store'), [...$input, 'purchaser' => [...$this->buyer, 'first_name' => 'Inna']])->assertSessionHasErrors('checkout_request_id');
    expect(Order::count())->toBe(1);
});

test('validation failures do not create an order or reach P24', function (array $overrides, string $field) {
    $input = checkoutHttpInput();
    $this->post(route('checkout.store'), array_replace($input, $overrides))->assertSessionHasErrors($field);
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    [['accept_regulations' => false], 'accept_regulations'], [['plan_revision' => 9], 'plan_revision'],
    [['for_other_person' => true], 'holder'], [['price' => '0,01'], 'price'], [['amount_grosz' => 1], 'amount_grosz'],
    [['holder_user_id' => 1], 'holder_user_id'], [['status' => 'paid'], 'status'], [['renewal_source_membership_id' => 1], 'renewal_source_membership_id'],
    [['purchaser' => ['email' => str_repeat('a', 40).'@example.test']], 'purchaser.email'],
]);

test('missing unpublished or changed regulation blocks submission', function () {
    $input = checkoutHttpInput();
    $new = new RegulationVersion;
    $new->forceFill(['version_number' => 2, 'body' => '<p>Nowy regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $this->post(route('checkout.store'), $input)->assertSessionHasErrors('accepted_regulation_version_id');
    Http::assertNothingSent();
    expect(Order::count())->toBe(0);
});

test('hidden plan or parent cannot be selected by direct link or forged submission', function (bool $parent) {
    $input = checkoutHttpInput();
    if ($parent) {
        PriceListCategory::where('key', $this->plan->category)->firstOrFail()->forceFill(['is_published' => false])->save();
    } else {
        $this->plan->forceFill(['is_public' => false])->save();
    }
    $this->get(route('checkout.create', ['plan' => $this->plan->id]))->assertNotFound();
    $this->post(route('checkout.store'), $input)->assertSessionHasErrors('membership_plan_id');
    Http::assertNothingSent();
})->with([false, true]);

test('unissued session grant cannot create read retry or return to another guest order', function () {
    $input = checkoutHttpInput();
    $order = app(CreateOnlineOrder::class)->handle(null, array_diff_key($input, ['checkout_request_id' => true]), $input['checkout_request_id']);
    $this->flushSession();
    $this->post(route('checkout.store'), $input)->assertNotFound();
    $this->get(route('checkout.show', $order->public_reference))->assertNotFound();
    $this->post(route('checkout.pay', $order->public_reference))->assertNotFound();
    $this->get(route('checkout.return', ['zamowienie' => $order->public_reference]))->assertNotFound();
    Http::assertNothingSent();
});

test('matching account contacts holder or administrator cannot access another account order', function () {
    $owner = User::factory()->create();
    $input = checkoutHttpInput();
    $order = app(CreateOnlineOrder::class)->handle($owner, array_diff_key($input, ['checkout_request_id' => true]), $input['checkout_request_id']);
    foreach ([User::factory()->create(['email' => $this->buyer['email'], 'phone' => $this->buyer['phone']]), User::factory()->admin()->create()] as $other) {
        $this->actingAs($other)->get(route('checkout.show', $order->public_reference))->assertNotFound();
        $this->post(route('checkout.pay', $order->public_reference))->assertNotFound();
    }
    $this->actingAs($owner)->get(route('checkout.show', $order->public_reference))->assertOk();
    Http::assertNothingSent();
});

test('guest purchase remains guest when the same browser later signs in', function () {
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::response(['data' => ['token' => 'token'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $account = User::factory()->create(['email' => $this->buyer['email']]);
    $this->actingAs($account)->get(route('checkout.show', Order::sole()->public_reference))->assertOk();
    $this->post(route('checkout.pay', Order::sole()->public_reference))->assertRedirect();
    expect(Order::sole()->purchaser_user_id)->toBeNull()->and($account->orders()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('form issued before a login cannot silently create an account purchase afterward', function () {
    $input = checkoutHttpInput();
    $this->actingAs(User::factory()->create())->post(route('checkout.store'), $input)->assertNotFound();
    expect(Order::count())->toBe(0);
    Http::assertNothingSent();
});

test('timeout preserves the scoped order and blocks another POST registration', function () {
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::failedConnection()]);
    $this->post(route('checkout.store'), $input)->assertRedirect(route('checkout.show', Order::sole()->public_reference));
    $this->get(route('checkout.show', Order::sole()->public_reference))->assertInertia(fn (Assert $page) => $page->where('order.state', 'checking_payment')->where('order.canPay', false));
    $this->post(route('checkout.pay', Order::sole()->public_reference))->assertRedirect();
    $this->post(route('checkout.store'), $input)->assertRedirect();
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)->and(Membership::count())->toBe(0);
    Http::assertSentCount(1);
});

test('known rejection can retry the same order and return URL cannot fake payment', function () {
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::sequence()->push([], 400)->push(['data' => ['token' => 'token'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $order = Order::sole();
    $this->post(route('checkout.pay', $order->public_reference))->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/token');
    $this->get(route('checkout.return', ['zamowienie' => $order->public_reference, 'status' => 'paid', 'paid' => true]))->assertRedirect(route('checkout.show', $order->public_reference));
    expect($order->fresh()->paid_at)->toBeNull()->and(Membership::count())->toBe(0)->and(Order::count())->toBe(1)->and(Payment::count())->toBe(2);
    Http::assertSentCount(2);
});

test('verified payment displays paid pending activation and does not register again', function () {
    $input = checkoutHttpInput();
    Http::fake(['*/transaction/register' => Http::response(['data' => ['token' => 'token'], 'responseCode' => 0]), '*/transaction/verify' => Http::response(['data' => ['status' => 'success'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $payment = Payment::sole();
    $data = ['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id, 'amount' => 12050, 'originAmount' => 12050,
        'currency' => 'PLN', 'orderId' => 987654, 'methodId' => 25, 'statement' => 'test'];
    $data['sign'] = hash('sha384', json_encode([...$data, 'crc' => 'fake-crc-secret']));
    app(VerifyPrzelewy24Payment::class)->handle(Przelewy24Notification::fromArray($data));
    $this->get(route('checkout.show', Order::sole()->public_reference))->assertInertia(fn (Assert $page) => $page->where('order.state', 'pending_activation')->where('order.canPay', false));
    $this->post(route('checkout.store'), $input)->assertRedirect(route('checkout.show', Order::sole()->public_reference));
    expect(Order::count())->toBe(1)->and(Membership::sole()->activated_at)->toBeNull()->and(DocumentDelivery::count())->toBe(1);
    Http::assertSentCount(2);
});

test('disabled payments and absent regulation show availability and cannot create orders', function () {
    $input = checkoutHttpInput();
    config(['payments.przelewy24.enabled' => false]);
    $this->get(route('checkout.create'))->assertInertia(fn (Assert $page) => $page->where('paymentAvailable', false));
    $this->post(route('checkout.store'), $input)->assertSessionHasErrors('payment');
    expect(Order::count())->toBe(0);
    Http::assertNothingSent();
});

test('browser checkout mutations retain CSRF protection', function () {
    $input = checkoutHttpInput();
    $this->app['env'] = 'local';
    try {
        $this->post(route('checkout.store'), $input)->assertStatus(419);
    } finally {
        $this->app['env'] = 'testing';
    }
    expect(Order::count())->toBe(0);
    Http::assertNothingSent();
});

test('expired guest session grant cannot authorize order access', function () {
    $input = checkoutHttpInput();
    Http::fake(['*' => Http::response(['data' => ['token' => 'token'], 'responseCode' => 0])]);
    $this->post(route('checkout.store'), $input)->assertRedirect();
    $this->travel(121)->minutes();
    $this->get(route('checkout.show', Order::sole()->public_reference))->assertNotFound();
});
