<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Payments\InitiatePrzelewy24Payment;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRegistrationException;
use App\Models\AuditLog;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Services\Payments\Przelewy24Client;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-24T12:00:00Z'));
    config(['payments.przelewy24' => [
        'enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => '123', 'pos_id' => '456',
        'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/payment/return', 'status_url' => 'https://merchant.example.test/payment/status',
    ]]);
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin.</p>', 'published_at' => now(), 'created_by_user_id' => User::factory()->admin()->create()->id])->save();
    $this->order = Order::factory()->create(['membership_plan_id' => MembershipPlan::factory()->create(['price_grosz' => 12050])->id, 'accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now(),
        'purchaser_email' => 'buyer@example.test', 'holder_email' => 'holder@example.test']);
});
afterEach(function () {
    // Dispose only isolated test attempts before reversing the registration tracking schema.
    DB::table('payments')->delete();
});

function p24Success(string $token = 'safe-payment-token'): array
{
    return ['data' => ['token' => $token], 'responseCode' => 0];
}

function assertP24Validation(callable $operation, string $field = 'payment'): void
{
    try {
        $operation();
        test()->fail('Expected payment validation failure.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
}

test('registration commits session before HTTP uses basic authentication and exact integer snapshot amount', function () {
    $checked = false;
    Http::fake(function (Request $request) use (&$checked) {
        $checked = true;
        expect(DB::transactionLevel())->toBe(0)->and(Payment::count())->toBe(1)
            ->and(Payment::sole()->provider_session_id)->toBe($request['sessionId'])
            ->and($request->url())->toBe('https://sandbox.przelewy24.pl/api/v1/transaction/register')
            ->and($request->header('Authorization'))->toBe(['Basic '.base64_encode('456:fake-api-secret')])
            ->and($request['amount'])->toBe(12050)->and($request['merchantId'])->toBe(123)->and($request['posId'])->toBe(456)
            ->and($request['currency'])->toBe('PLN')->and($request['email'])->toBe('buyer@example.test')
            ->and($request['urlReturn'])->toBe('https://merchant.example.test/payment/return?zamowienie='.$this->order->public_reference)
            ->and($request['urlStatus'])->toBe('https://merchant.example.test/payment/status');
        $expected = hash('sha384', json_encode(['sessionId' => $request['sessionId'], 'merchantId' => 123, 'amount' => 12050, 'currency' => 'PLN', 'crc' => 'fake-crc-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        expect($request['sign'])->toBe($expected)->and($request->data())->not->toHaveKeys(['client', 'phone', 'regulationAccept', 'crc']);

        return Http::response(p24Success());
    });
    $payment = app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null);
    expect($checked)->toBeTrue()->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->confirmed_at)->toBeNull()->and($payment->provider_transaction_id)->toBeNull()
        ->and($this->order->fresh()->paid_at)->toBeNull()->and($this->order->fresh()->status->value)->toBe('pending')
        ->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0)
        ->and(app(Przelewy24Client::class)->redirectUrl($payment))->toBe('https://sandbox.przelewy24.pl/trnRequest/safe-payment-token');
    expect($payment->getRawOriginal('provider_token'))->not->toBe('safe-payment-token');
    expect(json_encode($payment->toArray()))->not->toContain('safe-payment-token', 'fake-api-secret', 'fake-crc-secret', $payment->provider_session_id);
    expect(json_encode(AuditLog::where('subject_type', 'payment')->get()->toArray()))->not->toContain('fake-api-secret', 'fake-crc-secret', 'buyer@example.test', 'safe-payment-token');
});

test('repeated initiation reuses the existing token without another request or attempt', function () {
    Http::fake(['*' => Http::response(p24Success())]);
    $action = app(InitiatePrzelewy24Payment::class);
    $first = $action->handle($this->order->id, null);
    $second = $action->handle($this->order->id, null);
    expect($second->id)->toBe($first->id)->and($second->provider_token)->toBe('safe-payment-token')->and(Payment::count())->toBe(1);
    Http::assertSentCount(1);
});

test('known registration rejection permits bounded retries with new sessions and preserved history', function () {
    Http::fake(['*' => Http::response(['error' => 'private body must not be persisted'], 400)]);
    $action = app(InitiatePrzelewy24Payment::class);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        assertP24Validation(fn () => $action->handle($this->order->id, null));
    }
    assertP24Validation(fn () => $action->handle($this->order->id, null));
    expect(Payment::count())->toBe(3)->and(Payment::pluck('provider_session_id')->unique()->count())->toBe(3)
        ->and(Payment::where('status', 'failed')->count())->toBe(3)->and(Payment::query()->oldest('id')->firstOrFail()->failure_code)->toBe('registration_rejected')
        ->and(Membership::count())->toBe(0);
    Http::assertSentCount(3);
});

test('timeouts persist unknown state and never automatically POST again', function () {
    Http::fake(['*' => Http::failedConnection('fake-api-secret buyer@example.test')]);
    $action = app(InitiatePrzelewy24Payment::class);
    assertP24Validation(fn () => $action->handle($this->order->id, null));
    expect(Payment::sole()->status)->toBe(PaymentStatus::Unknown)->and(Payment::sole()->failure_code)->toBe('registration_connection_error');
    assertP24Validation(fn () => $action->handle($this->order->id, null));
    expect(Payment::count())->toBe(1)->and(Membership::count())->toBe(0);
});

test('server errors malformed payloads and redirects are uncertain and cannot create a pass', function (mixed $body, int $status) {
    Http::fake(['*' => Http::response($body, $status)]);
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    expect(Payment::sole()->status)->toBe(PaymentStatus::Unknown)->and(Membership::count())->toBe(0);
    Http::assertSentCount(1);
})->with([
    [[], 503], [[], 302], ['not-json', 200], [['data' => ['token' => 'token']], 200],
    [['data' => ['token' => 'token'], 'responseCode' => '0'], 200], [['data' => ['token' => ''], 'responseCode' => 0], 200],
    [['data' => ['token' => '../bad token'], 'responseCode' => 0], 200],
]);

test('disabled invalid and unsafe configuration sends no HTTP and creates no attempt', function (string $key, mixed $value) {
    config(['payments.przelewy24.'.$key => $value]);
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    ['enabled', false], ['environment', 'production'], ['environment', 'invalid'], ['merchant_id', ''], ['merchant_id', 0], ['pos_id', 'invalid'],
    ['api_key', ''], ['crc', ''], ['return_url', 'http://merchant.example.test/return'], ['status_url', 'https://user:secret@merchant.example.test/status'],
    ['return_url', 'https://merchant.example.test/return#fragment'],
]);

test('purchaser ownership is enforced independently of holder identity and staff role', function (string $kind) {
    $buyer = User::factory()->create();
    $actor = match ($kind) {
        'owner' => $buyer, 'holder' => User::factory()->create(), 'admin' => User::factory()->admin()->create(),
        'unsaved' => (new User)->forceFill(['id' => $buyer->id]), 'guest' => null, default => $buyer,
    };
    $order = Order::factory()->create(['purchaser_user_id' => $buyer->id, 'holder_user_id' => $kind === 'holder' ? $actor->id : null,
        'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    if ($kind === 'suspended') {
        $buyer->fresh()->forceFill(['suspended_at' => now()])->save();
    }
    Http::fake(['*' => Http::response(p24Success())]);
    if ($kind === 'owner') {
        expect(app(InitiatePrzelewy24Payment::class)->handle($order->id, $actor)->status)->toBe(PaymentStatus::Pending);
    } else {
        expect(fn () => app(InitiatePrzelewy24Payment::class)->handle($order->id, $actor))->toThrow(AuthorizationException::class);
        Http::assertNothingSent();
    }
})->with(['owner', 'holder', 'admin', 'unsaved', 'guest', 'suspended']);

test('receipt failed cancelled and already paid orders cannot be registered', function (string $kind) {
    $order = $this->order;
    if ($kind === 'reception') {
        $order = Order::factory()->paidAtReception()->create();
    } else {
        $order->forceFill(['status' => $kind, ...($kind === 'paid' ? ['paid_at' => now()] : [])])->save();
    }
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($order->id, null));
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
})->with(['reception', 'failed', 'cancelled', 'paid']);

test('provider email length limit is validated without truncating the purchaser snapshot', function () {
    $email = str_repeat('a', 39).'@example.test';
    $order = Order::factory()->create(['purchaser_email' => $email, 'accepted_regulation_version_id' => $this->order->accepted_regulation_version_id, 'regulation_accepted_at' => now()]);
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($order->id, null), 'purchaser.email');
    expect($order->fresh()->purchaser_email)->toBe($email)->and(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

test('reentrant initiation is blocked while the first HTTP request is in flight', function () {
    Http::fake(function () {
        assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
        expect(Payment::count())->toBe(1);

        return Http::response(p24Success());
    });
    app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null);
    Http::assertSentCount(1);
});

test('interrupted registration becomes unknown and config drift does not create another attempt', function () {
    Http::fake(function () {
        $this->travel(61)->seconds();
        assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));

        return Http::response(p24Success());
    });
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    expect(Payment::sole()->status)->toBe(PaymentStatus::Unknown)->and(Payment::sole()->failure_code)->toBe('registration_interrupted');
    config(['payments.przelewy24.pos_id' => 999]);
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    expect(Payment::count())->toBe(1);
    Http::assertSentCount(1);
});

test('audit failure before request leaves no attempt and no external side effect', function () {
    $this->mock(RecordAudit::class)->shouldReceive('handle')->andThrow(new RuntimeException('Test audit failure'));
    expect(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null))->toThrow(RuntimeException::class);
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

test('failed response persistence leaves a durable session and cannot repeat registration', function () {
    Http::fake(['*' => Http::response(p24Success())]);
    Payment::updating(fn () => throw new RuntimeException('Test payment persistence failure'));
    try {
        expect(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null))->toThrow(PaymentRegistrationException::class);
    } finally {
        Event::forget('eloquent.updating: '.Payment::class);
    }
    expect(Payment::sole()->status)->toBe(PaymentStatus::Initiated)->and(Payment::sole()->provider_token)->toBeNull();
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    Http::assertSentCount(1);
});

test('registration refuses an outer transaction so remote work never precedes durable commit', function () {
    expect(fn () => DB::transaction(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null)))->toThrow(LogicException::class);
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

test('return redirects remain on a pinned provider host and token is immutable', function () {
    Http::fake(['*' => Http::response(p24Success('token/with?special=chars'))]);
    $payment = app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null);
    expect(app(Przelewy24Client::class)->redirectUrl($payment))->toBe('https://sandbox.przelewy24.pl/trnRequest/token%2Fwith%3Fspecial%3Dchars');
    expect(fn () => $payment->forceFill(['provider_token' => 'replacement'])->save())->toThrow(LogicException::class);
    config(['payments.przelewy24.merchant_id' => 999]);
    assertP24Validation(fn () => app(Przelewy24Client::class)->redirectUrl($payment));
});

test('a verified payment completed during registration cannot be downgraded by its response', function () {
    Http::fake(function () {
        DB::transaction(function () {
            $this->order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            Payment::sole()->forceFill(['status' => 'confirmed', 'confirmed_at' => now(), 'provider_transaction_id' => '987654'])->save();
        });

        return Http::response(p24Success());
    });
    assertP24Validation(fn () => app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null));
    expect(Payment::sole()->status)->toBe(PaymentStatus::Confirmed)->and($this->order->fresh()->status->value)->toBe('paid')
        ->and(Payment::sole()->provider_token)->toBeNull();
});
