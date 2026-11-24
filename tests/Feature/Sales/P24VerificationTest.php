<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Memberships\ActivateMembership;
use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentVerificationException;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Services\Payments\Przelewy24Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-24T12:00:00Z'));
    config(['payments.przelewy24' => [
        'enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => '123', 'pos_id' => '456',
        'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/return', 'status_url' => 'https://merchant.example.test/status',
    ]]);
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $this->order = Order::factory()->create(['membership_plan_id' => MembershipPlan::factory()->create(['price_grosz' => 12050])->id,
        'accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now(),
        'purchaser_email' => 'buyer@example.test', 'holder_email' => 'holder@example.test', 'recipient_email' => 'holder@example.test']);
    $this->payment = Payment::factory()->create(['order_id' => $this->order->id, 'status' => 'pending', 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456, 'registration_started_at' => now(),
        'registration_finished_at' => now(), 'provider_token' => 'private-token']);
});

afterEach(function () {
    // Dispose only isolated test fixtures before reversing history-preserving migrations.
    $ids = DB::table('memberships')->whereNotNull('renewal_root_membership_id')->orderByDesc('id')->pluck('id');
    DB::table('orders')->whereNotNull('renewal_source_membership_id')->update(['renewal_source_membership_id' => null]);
    DB::table('document_deliveries')->whereIn('membership_id', $ids)->delete();
    foreach ($ids as $id) {
        DB::table('memberships')->where('id', $id)->delete();
    }
    DB::table('payments')->delete();
});

function signedP24Callback(Payment $payment, array $overrides = []): array
{
    $data = array_replace(['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id,
        'amount' => $payment->amount_grosz, 'originAmount' => $payment->amount_grosz, 'currency' => 'PLN',
        'orderId' => 987654, 'methodId' => 25, 'statement' => ' Zażółć / '], $overrides);
    $data['sign'] = hash('sha384', json_encode([...$data, 'crc' => 'fake-crc-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $data;
}

function p24VerifiedResponse(): array
{
    return ['data' => ['status' => 'success'], 'responseCode' => 0];
}

function p24LookupResponse(Payment $payment, array $overrides = []): array
{
    return ['data' => array_replace(['sessionId' => $payment->provider_session_id, 'orderId' => 987654,
        'amount' => $payment->amount_grosz, 'currency' => 'PLN', 'status' => 2,
        'clientEmail' => 'secret-holder@example.test'], $overrides), 'responseCode' => 0];
}

test('verified notification atomically pays issues a pending pass and queues its holder PDF once', function () {
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe(0)->and(Payment::sole()->provider_transaction_id)->toBe('987654')
            ->and($request->method())->toBe('PUT')->and($request->url())->toBe('https://sandbox.przelewy24.pl/api/v1/transaction/verify')
            ->and($request->header('Authorization'))->toBe(['Basic '.base64_encode('456:fake-api-secret')])
            ->and($request['merchantId'])->toBe(123)->and($request['posId'])->toBe(456)->and($request['orderId'])->toBe(987654)
            ->and($request['amount'])->toBe(12050)->and($request['currency'])->toBe('PLN');
        $json = json_encode(['sessionId' => $request['sessionId'], 'orderId' => 987654, 'amount' => 12050, 'currency' => 'PLN', 'crc' => 'fake-crc-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        expect($request['sign'])->toBe(hash('sha384', $json));

        return Http::response(p24VerifiedResponse());
    });
    $payload = signedP24Callback($this->payment);
    $this->postJson(route('payments.przelewy24.notification'), $payload)->assertOk()->assertContent('OK');
    $membership = Membership::sole();
    $paidAt = $this->order->fresh()->paid_at;
    expect($this->order->fresh()->status->value)->toBe('paid')->and($paidAt->toIso8601String())->toBe('2026-11-24T12:00:00+00:00')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Confirmed)->and($this->payment->fresh()->confirmed_by_user_id)->toBeNull()
        ->and($membership->starts_on)->toBeNull()->and($membership->activated_at)->toBeNull()->and($membership->expires_on)->toBeNull()
        ->and(DocumentDelivery::sole()->recipient_email)->toBe('holder@example.test')
        ->and(AuditLog::where('action', 'payment.verified')->count())->toBe(1);
    $this->travel(5)->minutes();
    $this->postJson(route('payments.przelewy24.notification'), $payload)->assertOk();
    expect(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)->and($this->order->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($this->payment->fresh()->verification_attempts)->toBe(1)->and(AuditLog::where('action', 'payment.verified')->count())->toBe(1);
    Http::assertSentCount(1);
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('private-token', 'fake-crc-secret', 'buyer@example.test', 'Zażółć');
});

test('signed mismatches and malformed types cannot reach the provider', function (array $overrides) {
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment, $overrides))->assertStatus(400);
    expect(Membership::count())->toBe(0)->and($this->order->fresh()->paid_at)->toBeNull()->and($this->payment->fresh()->verification_attempts)->toBe(0);
    Http::assertNothingSent();
})->with([
    [['amount' => 1]], [['originAmount' => 1]], [['currency' => 'EUR']], [['merchantId' => 999]], [['posId' => 999]],
    [['sessionId' => 'unknown']], [['orderId' => 0]], [['orderId' => '987654']], [['amount' => '12050']], [['amount' => 12050.5]],
    [['methodId' => -1]], [['statement' => null]], [['merchantId' => true]], [['sessionId' => str_repeat('a', 101)]],
]);

test('tampering with every signed field is rejected before HTTP', function (string $field, mixed $value) {
    $payload = signedP24Callback($this->payment);
    $payload[$field] = $value;
    $this->postJson(route('payments.przelewy24.notification'), $payload)->assertStatus(400);
    Http::assertNothingSent();
    expect(Membership::count())->toBe(0);
})->with([
    ['amount', 99], ['originAmount', 99], ['currency', 'EUR'], ['orderId', 999], ['methodId', 26], ['statement', 'other'],
    ['sessionId', 'other'], ['sign', str_repeat('0', 96)],
]);

test('GET browser returns invalid JSON and oversized bodies never finalize a payment', function () {
    $url = route('payments.przelewy24.notification');
    $this->getJson($url.'?'.http_build_query(signedP24Callback($this->payment)))->assertStatus(405);
    $this->post($url, signedP24Callback($this->payment))->assertStatus(400);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid')->assertStatus(400);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 8193))->assertStatus(400);
    expect(Membership::count())->toBe(0);
    Http::assertNothingSent();
});

test('operator failure leaves traceable unknown payment without a pass', function (mixed $body, int $status) {
    Http::fake(['*/transaction/verify' => Http::response($body, $status), '*/transaction/by/sessionId/*' => Http::response([], 404)]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(503)->assertContent('Retry later');
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Unknown)->and($this->payment->fresh()->provider_transaction_id)->toBe('987654')
        ->and($this->payment->fresh()->verification_attempts)->toBe(1)->and($this->payment->fresh()->verification_failure_code)->toBe('verification_rejected')
        ->and($this->order->fresh()->paid_at)->toBeNull()->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0);
    Http::assertSentCount(2);
})->with([[[], 500], [[], 400], [[], 401], [[], 302], ['not-json', 200], [['data' => ['status' => 'success'], 'responseCode' => '0'], 200], [['responseCode' => 0, 'data' => ['status' => 'failure']], 200]]);

test('lost verification response is recovered only from matching authenticated settled state', function () {
    Http::fake(['*/transaction/verify' => Http::failedConnection('fake-api-secret buyer@example.test'),
        '*/transaction/by/sessionId/*' => Http::response(p24LookupResponse($this->payment))]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    expect(Membership::count())->toBe(1)->and($this->payment->fresh()->status)->toBe(PaymentStatus::Confirmed);
    Http::assertSentCount(2);
});

test('lookup mismatches unpaid advance or returned states cannot replace failed verification', function (array $overrides) {
    Http::fake(['*/transaction/verify' => Http::response([], 400), '*/transaction/by/sessionId/*' => Http::response(p24LookupResponse($this->payment, $overrides))]);
    $response = $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment));
    expect(in_array($response->status(), [400, 503], true))->toBeTrue()->and(Membership::count())->toBe(0)->and($this->order->fresh()->paid_at)->toBeNull();
})->with([[['amount' => 1]], [['currency' => 'EUR']], [['sessionId' => 'other']], [['orderId' => 111]], [['status' => 0]], [['status' => 1]], [['status' => 3]], [['status' => '2']]]);

test('issuance failure rolls back payment order pass outbox and audit but retains retry identity', function () {
    Http::fake(['*/transaction/verify' => Http::response(p24VerifiedResponse())]);
    $audit = Mockery::mock(RecordAudit::class)->makePartial();
    $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('fake injected failure'));
    $this->app->instance(RecordAudit::class, $audit);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(503);
    expect(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)->and($this->order->fresh()->paid_at)->toBeNull()
        ->and($this->payment->fresh()->confirmed_at)->toBeNull()->and($this->payment->fresh()->provider_transaction_id)->toBe('987654')
        ->and($this->payment->fresh()->verification_failure_code)->toBe('issuance_persistence_error');
    $this->app->forgetInstance(RecordAudit::class);
    Http::fake(['*/transaction/by/sessionId/*' => Http::response(p24LookupResponse($this->payment))]);
    app(VerifyPrzelewy24Payment::class)->reconcile($this->payment->id);
    expect(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1)->and($this->payment->fresh()->verification_attempts)->toBe(2);
});

test('reconciliation recovers unknown or interrupted sessions without registering again', function (int $status) {
    DB::table('payments')->where('id', $this->payment->id)->update(['status' => 'unknown', 'provider_token' => null]);
    Http::fake(['*/transaction/by/sessionId/*' => Http::response(p24LookupResponse($this->payment, ['status' => $status])), '*/transaction/verify' => Http::response(p24VerifiedResponse())]);
    expect(app(VerifyPrzelewy24Payment::class)->reconcile($this->payment->id))->toBeInstanceOf(Membership::class)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Confirmed)->and($this->payment->fresh()->provider_token)->toBeNull();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([1, 2]);

test('missing or unpaid lookup never marks failure or permits another registration', function (int $status) {
    Http::fake(['*' => $status === 404 ? Http::response([], 404) : Http::response(p24LookupResponse($this->payment, ['status' => 0, 'orderId' => 0]))]);
    expect(app(VerifyPrzelewy24Payment::class)->reconcile($this->payment->id))->toBeNull()
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Pending)->and($this->payment->fresh()->verification_attempts)->toBe(1)
        ->and($this->payment->fresh()->verification_last_attempt_at)->not->toBeNull()->and(Membership::count())->toBe(0);
})->with([0, 404]);

test('registered identity suspension does not interfere with signed server notifications', function () {
    $customer = User::factory()->suspended()->create();
    $this->actingAs($customer);
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    expect(Membership::sole()->holder_user_id)->toBeNull()->and($this->order->fresh()->purchaser_user_id)->toBeNull();
    $middleware = app('router')->getRoutes()->getByName('payments.przelewy24.notification')->gatherMiddleware();
    expect($middleware)->not->toContain('web', 'auth');
});

test('changed account configuration and stopped order or payment cannot be confirmed', function (string $table, array $attributes) {
    DB::table($table)->where('id', $table === 'orders' ? $this->order->id : $this->payment->id)->update($attributes);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(400);
    Http::assertNothingSent();
    expect(Membership::count())->toBe(0);
})->with([
    ['payments', ['p24_environment' => 'production']], ['payments', ['p24_merchant_id' => 124]], ['payments', ['p24_pos_id' => 457]],
    ['payments', ['status' => 'failed']], ['payments', ['status' => 'cancelled']], ['orders', ['status' => 'cancelled']], ['orders', ['status' => 'failed']],
]);

test('diagnostics contain fixed codes without provider bodies or customer contacts', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'buyer@example.test fake-api-secret'], 500)]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(503);
    Log::shouldHaveReceived('warning')->with('P24 notification not finalized.', ['reason' => 'lookup_unavailable'])->once();
    expect($this->payment->fresh()->verification_failure_code)->toBe('lookup_unavailable');
});

test('outer database transactions cannot perform provider verification', function () {
    expect(fn () => DB::transaction(fn () => app(VerifyPrzelewy24Payment::class)->handle(Przelewy24Notification::fromArray(signedP24Callback($this->payment)))))
        ->toThrow(LogicException::class);
    Http::assertNothingSent();
});

test('bounded reconciliation command validates options and uses only existing sessions', function () {
    Http::fake(['*' => Http::response(p24LookupResponse($this->payment))]);
    $this->artisan('payments:reconcile-p24', ['--limit' => 0])->assertExitCode(2);
    Http::assertNothingSent();
    $this->artisan('payments:reconcile-p24', ['--payment' => $this->payment->id, '--limit' => 1])->assertSuccessful();
    expect(Membership::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a different transaction cannot replace the ID on a replayed valid notification', function () {
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment, ['orderId' => 987655]))->assertStatus(400);
    expect($this->payment->fresh()->provider_transaction_id)->toBe('987654')->and(Membership::count())->toBe(1);
    Http::assertSentCount(1);
});

test('the same provider transaction cannot pay another order or session', function () {
    $otherOrder = Order::factory()->create(['accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    $otherPayment = Payment::factory()->create(['order_id' => $otherOrder->id, 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456]);
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($otherPayment))->assertStatus(400);
    expect($otherOrder->fresh()->paid_at)->toBeNull()->and($otherPayment->fresh()->provider_transaction_id)->toBeNull()->and(Membership::count())->toBe(1);
    Http::assertSentCount(1);
});

test('interrupted registration can be confirmed without a saved redirect token', function () {
    DB::table('payments')->where('id', $this->payment->id)->update(['status' => 'initiated', 'provider_token' => null, 'registration_finished_at' => null]);
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Confirmed)->and($this->payment->fresh()->provider_token)->toBeNull()
        ->and(Membership::count())->toBe(1);
});

test('entry pass remains pending activation with its purchased balance and guest identity', function () {
    $account = User::factory()->create(['email' => 'holder@example.test']);
    $plan = MembershipPlan::factory()->create(['kind' => 'entries', 'duration_months' => null, 'included_entries' => 8]);
    $order = Order::factory()->create(['membership_plan_id' => $plan->id, 'holder_email' => $account->email,
        'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456]);
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($payment))->assertOk();
    $membership = Membership::sole();
    expect($membership->entries_granted)->toBe(8)->and($membership->entries_remaining)->toBe(8)
        ->and($membership->activated_at)->toBeNull()->and($membership->starts_on)->toBeNull()->and($membership->holder_user_id)->toBeNull()
        ->and($order->fresh()->purchaser_user_id)->toBeNull();
});

test('catalog changes cannot change verified purchased terms', function () {
    MembershipPlan::findOrFail($this->order->membership_plan_id)->forceFill(['price_grosz' => 99999, 'duration_months' => 12, 'is_active' => false])->save();
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    expect(Membership::sole()->plan_snapshot)->toEqual($this->order->plan_snapshot)->and($this->order->fresh()->amount_grosz)->toBe(12050);
});

test('returned remote payment is flagged for review without implementing refunds', function () {
    Http::fake(['*' => Http::response(p24LookupResponse($this->payment, ['status' => 3]))]);
    expect(fn () => app(VerifyPrzelewy24Payment::class)->reconcile($this->payment->id))->toThrow(PaymentVerificationException::class);
    expect($this->payment->fresh()->verification_failure_code)->toBe('returned_payment_requires_review')
        ->and($this->order->fresh()->paid_at)->toBeNull()->and(Membership::count())->toBe(0);
    Http::assertSentCount(1);
});

test('disabled gateway yields retryable callback failure without contacting P24', function () {
    config(['payments.przelewy24.enabled' => false]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(503);
    $this->artisan('payments:reconcile-p24')->assertFailed();
    Http::assertNothingSent();
    expect(Membership::count())->toBe(0);
});

test('notification rate limit protects the stateless endpoint', function () {
    $route = app('router')->getRoutes()->getByName('payments.przelewy24.notification');
    expect($route->gatherMiddleware())->toContain('throttle:120,1,p24-notification:');
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    for ($attempt = 0; $attempt < 120; $attempt++) {
        $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    }
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertStatus(429);
    expect(Membership::count())->toBe(1);
    Http::assertSentCount(1);
});

test('verified prebound renewal appends its future period and replay preserves the chain', function () {
    Http::fake(['*' => Http::response(p24VerifiedResponse())]);
    $this->postJson(route('payments.przelewy24.notification'), signedP24Callback($this->payment))->assertOk();
    $source = app(ActivateMembership::class)->handle(User::factory()->employee()->create(), Membership::sole()->id);
    $attributes = ['membership_plan_id' => $this->order->membership_plan_id, 'renewal_source_membership_id' => $source->id,
        'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()];
    foreach (['holder_first_name', 'holder_last_name', 'holder_email', 'holder_phone'] as $field) {
        $attributes[$field] = $this->order->getAttribute($field);
    }
    $order = Order::factory()->create($attributes);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'provider_session_id' => (string) Str::uuid(),
        'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456]);
    $data = signedP24Callback($payment, ['orderId' => 987655]);
    $this->postJson(route('payments.przelewy24.notification'), $data)->assertOk();
    $this->postJson(route('payments.przelewy24.notification'), $data)->assertOk();
    $renewal = Membership::where('order_id', $order->id)->sole();
    expect($renewal->starts_on->toDateString())->toBe($source->expires_on->addDay()->toDateString())
        ->and($renewal->activated_at)->toBeNull()->and($renewal->renewed_from_id)->toBe($source->id)
        ->and(Membership::count())->toBe(2)->and(DocumentDelivery::count())->toBe(2);
    Http::assertSentCount(2);
});
