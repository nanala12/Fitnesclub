<?php

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);
afterEach(fn () => DB::table('orders')->update(['checkout_request_id' => null]));

test('checkout request migration preserves historical constraints and forbids erasing used keys', function () {
    $order = Order::factory()->create();
    $before = $order->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_24_030000_add_online_checkout_request.php');
    $migration->down();
    $migration->up();
    expect($order->fresh()->getAttributes())->toBe($before);
    $id = (string) Str::uuid();
    $order = Order::factory()->create(['checkout_request_id' => $id, 'checkout_fingerprint' => str_repeat('a', 64)]);
    expect($order->toArray())->not->toHaveKeys(['checkout_request_id', 'checkout_fingerprint']);
    expect(fn () => Order::factory()->create(['checkout_request_id' => $id]))->toThrow(QueryException::class);
    expect(fn () => $order->forceFill(['checkout_request_id' => (string) Str::uuid()])->save())->toThrow(LogicException::class);
    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['amount_grosz' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['membership_plan_id' => 999999]))->toThrow(QueryException::class);
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
});
