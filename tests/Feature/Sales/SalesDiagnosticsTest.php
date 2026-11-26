<?php

use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now()->setMicrosecond(0));
    config(['payments.przelewy24' => ['enabled' => true, 'environment' => 'sandbox', 'live_enabled' => false,
        'merchant_id' => 123, 'pos_id' => 456, 'api_key' => 'fake-api-secret', 'crc' => 'fake-crc-secret',
        'return_url' => 'https://merchant.example.test/platnosc/powrot', 'status_url' => 'https://merchant.example.test/payments/przelewy24/notification']]);
});

afterEach(function () {
    DB::table('document_deliveries')->delete();
    DB::table('payments')->delete();
});

test('diagnostics report disabled configuration without external access or writes', function () {
    config(['payments.przelewy24.enabled' => false]);
    expect(Artisan::call('sales:diagnose'))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['p24']['configuration_valid'])->toBeFalse()->and($report['p24']['external_connectivity'])->toBe('not_checked')
        ->and($report['processes']['queue_worker'])->toBe('not_checked')->and($report['payments'])->toBe([]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('diagnostics expose bounded processing evidence without secrets contacts paths or exception details', function () {
    $order = Order::factory()->create();
    $payment = Payment::factory()->create(['order_id' => $order->id, 'status' => 'unknown', 'provider_session_id' => 'private-provider-session',
        'provider_token' => 'private-provider-token', 'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456,
        'verification_attempts' => 2, 'verification_last_attempt_at' => now()->subMinute(), 'verification_failure_code' => 'exception holder@example.test']);
    $member = Membership::factory()->create(['order_id' => $order->id]);
    $delivery = DocumentDelivery::factory()->create(['membership_id' => $member->id, 'status' => 'processing', 'attempts_count' => 1,
        'processing_token' => (string) Str::uuid(), 'processing_started_at' => now()->subSeconds(181),
        'storage_path' => 'private/customer-name.pdf', 'last_error_code' => 'exception fake-api-secret']);
    $before = [$payment->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal()];
    expect(Artisan::call('sales:diagnose', ['--order' => $order->public_reference, '--limit' => 1]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['p24']['configuration_valid'])->toBeTrue()->and($report['p24']['sandbox'])->toBeTrue()
        ->and($report['p24']['callback_path_matches'])->toBeTrue()->and($report['p24']['return_path_matches'])->toBeTrue()
        ->and($report['payments'][0]['can_reconcile'])->toBeTrue()->and($report['payments'][0]['verification_attempts'])->toBe(2)
        ->and($report['deliveries'][0]['processing_expired'])->toBeTrue()->and($report['deliveries'][0]['pdf_recorded'])->toBeTrue();
    foreach (['fake-api-secret', 'fake-crc-secret', 'private-provider-session', 'private-provider-token', 'holder@example.test', 'buyer@example.test', 'customer-name.pdf', $delivery->processing_token] as $private) {
        expect($output)->not->toContain($private);
    }
    expect([$payment->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal()])->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('diagnostics scope all sections and flag missing issuance without trying to repair it', function () {
    $missing = Order::factory()->paidAtReception()->create();
    $foreign = Order::factory()->create();
    Payment::factory()->create(['order_id' => $foreign->id]);
    expect(Artisan::call('sales:diagnose', ['--order' => $missing->public_reference]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['paid_orders_without_membership'])->toBe([$missing->id])->and($report['payments'])->toBe([])->and($report['deliveries'])->toBe([])
        ->and(Membership::count())->toBe(0)->and($missing->fresh()->status->value)->toBe('paid');
    Http::assertNothingSent();
});

test('namespace mismatches and terminal attempts cannot be reconciled and route mismatch is visible', function () {
    config(['payments.przelewy24.status_url' => 'https://merchant.example.test/wrong']);
    Payment::factory()->create(['status' => 'unknown', 'provider_session_id' => 'session', 'p24_environment' => 'sandbox', 'p24_merchant_id' => 999, 'p24_pos_id' => 456]);
    Payment::factory()->create(['status' => 'failed', 'provider_session_id' => 'session-two', 'p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'p24_pos_id' => 456]);
    expect(Artisan::call('sales:diagnose', ['--limit' => 1]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['p24']['callback_path_matches'])->toBeFalse()->and($report['payments'])->toHaveCount(1)->and($report['payments'][0]['can_reconcile'])->toBeFalse();
    Http::assertNothingSent();
});

test('diagnostics reject malformed bounds or references', function (array $options) {
    expect(Artisan::call('sales:diagnose', $options))->toBe(2);
    Http::assertNothingSent();
})->with([[['--limit' => 0]], [['--limit' => 101]], [['--order' => 'not-an-order']]]);

test('unknown order reference does not fall back to global diagnostics', function () {
    expect(Artisan::call('sales:diagnose', ['--order' => (string) Str::ulid()]))->toBe(1);
    expect(Artisan::output())->not->toContain('payments', 'deliveries');
});
