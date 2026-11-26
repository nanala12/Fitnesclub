<?php

use App\Actions\Documents\RequestDocumentResend;
use App\Actions\Payments\InitiatePrzelewy24Payment;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Storage::fake('membership_documents');
    $this->travelTo(now()->setMicrosecond(0));
    config(['mail.mailers.membership.transport' => 'array', 'payments.przelewy24' => [
        'enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false, 'merchant_id' => 123, 'pos_id' => 456,
        'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/platnosc/powrot', 'status_url' => 'https://merchant.example.test/payments/przelewy24/notification',
    ]]);
    $this->admin = User::factory()->admin()->create();
    $version = new RegulationVersion;
    $version->forceFill(['version_number' => 1, 'body' => '<p>Regulamin testowy.</p>', 'published_at' => now(), 'created_by_user_id' => $this->admin->id])->save();
    $this->order = Order::factory()->create(['accepted_regulation_version_id' => $version->id, 'regulation_accepted_at' => now(),
        'holder_email' => 'holder@example.test', 'recipient_email' => 'holder@example.test']);
});

afterEach(function () {
    // Remove only disposable test fixtures before guarded history migrations roll back.
    DB::table('document_delivery_attempts')->delete();
    foreach (DB::table('document_deliveries')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('document_deliveries')->where('id', $id)->delete();
    }
    DB::table('payments')->delete();
});

test('runbook recovers delayed payment failed mail lost queue dispatch and explicit resend without reissuing', function () {
    $settled = false;
    Http::fake(function (Request $request) use (&$settled) {
        expect(DB::transactionLevel())->toBe(0);
        if (str_ends_with($request->url(), '/transaction/register')) {
            return Http::response(['data' => ['token' => 'recovery-token'], 'responseCode' => 0]);
        }
        if (str_ends_with($request->url(), '/transaction/verify')) {
            return Http::failedConnection('Synthetic unavailable verification');
        }
        if (str_contains($request->url(), '/transaction/by/sessionId/')) {
            $payment = Payment::sole();

            return $settled ? Http::response(['data' => ['sessionId' => $payment->provider_session_id, 'orderId' => 987654,
                'amount' => $payment->amount_grosz, 'currency' => 'PLN', 'status' => 2], 'responseCode' => 0]) : Http::response([], 404);
        }
        throw new RuntimeException('Unexpected fake request');
    });
    $payment = app(InitiatePrzelewy24Payment::class)->handle($this->order->id, null);
    $callback = ['merchantId' => 123, 'posId' => 456, 'sessionId' => $payment->provider_session_id, 'amount' => $payment->amount_grosz,
        'originAmount' => $payment->amount_grosz, 'currency' => 'PLN', 'orderId' => 987654, 'methodId' => 25, 'statement' => 'recovery test'];
    $callback['sign'] = hash('sha384', json_encode([...$callback, 'crc' => 'fake-crc-secret']));
    $url = route('payments.przelewy24.notification');
    $this->postJson($url, [...$callback, 'amount' => 1])->assertStatus(400);
    Http::assertSentCount(1);
    $this->postJson($url, $callback)->assertStatus(503);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Unknown)->and(Membership::count())->toBe(0)->and(DocumentDelivery::count())->toBe(0);

    $settled = true;
    $this->artisan('payments:reconcile-p24', ['--payment' => $payment->id, '--limit' => 1])->assertSuccessful();
    $member = Membership::sole();
    $delivery = DocumentDelivery::sole();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed)->and($member->starts_on)->toBeNull()->and($member->expires_on)->toBeNull();
    $financial = [$this->order->fresh()->getRawOriginal(), $payment->fresh()->getRawOriginal(), $member->fresh()->getRawOriginal()];
    $this->postJson($url, $callback)->assertOk();
    $this->artisan('payments:reconcile-p24', ['--payment' => $payment->id, '--limit' => 1])->assertSuccessful();
    expect(Membership::count())->toBe(1)->and(DocumentDelivery::count())->toBe(1);
    Http::assertSentCount(4); // Register, failed verify, missing lookup, settled lookup; no re-registration.

    $mailFails = true;
    $accepted = 0;
    Event::listen(MessageSending::class, function () use (&$mailFails) {
        expect(DB::transactionLevel())->toBe(0);
        if ($mailFails) {
            throw new RuntimeException('Synthetic SMTP failure fake-api-secret holder@example.test');
        }
    });
    Event::listen(MessageSent::class, function () use (&$accepted) {
        $accepted++;
    });
    // Simulate a lost dispatch: the fake queue retained no durable database job.
    Queue::swap($this->realQueue);
    expect(DB::table('jobs')->count())->toBe(0);
    $this->travel(61)->seconds();
    $this->artisan('documents:retry-deliveries', ['--limit' => 1])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1, '--timeout' => 60])->assertSuccessful();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)->and($delivery->fresh()->attempts_count)->toBe(1)
        ->and(DocumentDeliveryAttempt::sole()->error_code)->toBe('mail_transport_failed')->and($accepted)->toBe(0);
    expect(Artisan::call('sales:diagnose', ['--order' => $this->order->public_reference]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['deliveries'][0]['attempts'])->toBe(1)->and($report['deliveries'][0]['needs_review'])->toBeFalse()
        ->and($report['paid_orders_without_membership'])->toBe([]);

    $mailFails = false;
    $this->travel(60)->seconds();
    $this->artisan('documents:retry-deliveries', ['--limit' => 1])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--stop-when-empty' => true, '--memory' => 512, '--tries' => 1, '--timeout' => 60])->assertSuccessful();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Sent)->and($delivery->fresh()->attempts_count)->toBe(2)
        ->and($accepted)->toBe(1)->and(DocumentDeliveryAttempt::count())->toBe(2);
    $sentAt = $delivery->fresh()->sent_at->toISOString();

    $requestId = (string) Str::uuid();
    $resend = app(RequestDocumentResend::class)->handle($this->admin, $delivery->id, $requestId);
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1, '--timeout' => 60])->assertSuccessful();
    expect(app(RequestDocumentResend::class)->handle($this->admin, $delivery->id, $requestId)->id)->toBe($resend->id)
        ->and($resend->fresh()->status)->toBe(DeliveryStatus::Sent)->and($delivery->fresh()->sent_at->toISOString())->toBe($sentAt)
        ->and($accepted)->toBe(2)->and(DocumentDelivery::count())->toBe(2)->and(Membership::count())->toBe(1)
        ->and(Order::count())->toBe(1)->and(Payment::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
    expect([$this->order->fresh()->getRawOriginal(), $payment->fresh()->getRawOriginal(), $member->fresh()->getRawOriginal()])->toBe($financial);
});
