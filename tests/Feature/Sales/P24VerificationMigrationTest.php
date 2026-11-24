<?php

use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);
afterEach(fn () => DB::table('payments')->delete());

test('verification migration preserves historical payments constraints and provider transaction uniqueness', function () {
    $payment = Payment::factory()->create(['p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'provider_transaction_id' => '987654']);
    $before = $payment->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_24_020000_add_p24_verification_tracking.php');
    $migration->down();
    $duplicate = Payment::factory()->create(['p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'provider_transaction_id' => '987654']);
    expect(fn () => $migration->up())->toThrow(LogicException::class, 'Duplicate');
    DB::table('payments')->where('id', $duplicate->id)->delete();
    $migration->up();
    expect($payment->fresh()->getAttributes())->toBe($before);
    expect(fn () => Payment::factory()->create(['p24_environment' => 'sandbox', 'p24_merchant_id' => 123, 'provider_transaction_id' => '987654']))->toThrow(QueryException::class);
    // Separate provider environments and merchant accounts are distinct transaction namespaces.
    Payment::factory()->create(['p24_environment' => 'production', 'p24_merchant_id' => 123, 'provider_transaction_id' => '987654']);
    Payment::factory()->create(['p24_environment' => 'sandbox', 'p24_merchant_id' => 124, 'provider_transaction_id' => '987654']);
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount_grosz' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['order_id' => 999999]))->toThrow(QueryException::class);
    DB::table('payments')->where('id', $payment->id)->update(['verification_attempts' => 1]);
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
});
