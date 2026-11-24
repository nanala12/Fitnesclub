<?php

use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

test('registration migration preserves payment history and enforces unique provider sessions', function () {
    $payment = Payment::factory()->create();
    $before = $payment->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_24_010000_add_p24_registration_tracking.php');
    $migration->down();
    $legacy = 'legacy-'.Str::uuid();
    DB::table('payments')->where('id', $payment->id)->update(['provider_session_id' => $legacy]);
    $other = Payment::factory()->create(['provider_session_id' => $legacy]);
    expect(fn () => $migration->up())->toThrow(LogicException::class, 'Duplicate');
    DB::table('payments')->where('id', $other->id)->delete();
    DB::table('payments')->where('id', $payment->id)->update(['provider_session_id' => null]);
    $migration->up();
    expect($payment->fresh()->getAttributes())->toBe($before);
    DB::table('payments')->where('id', $payment->id)->update(['provider_session_id' => $legacy]);
    expect(fn () => Payment::factory()->create(['provider_session_id' => $legacy]))->toThrow(QueryException::class);
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount_grosz' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['order_id' => 999999]))->toThrow(QueryException::class);
    DB::table('payments')->where('id', $payment->id)->update(['registration_started_at' => now()]);
    try {
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
    } finally {
        DB::table('payments')->where('id', $payment->id)->update(['registration_started_at' => null]);
    }
});
