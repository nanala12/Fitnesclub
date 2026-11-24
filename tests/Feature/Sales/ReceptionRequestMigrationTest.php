<?php

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

test('receipt request migration preserves historical snapshots checks and unique request ownership', function () {
    $order = Order::factory()->create();
    $before = $order->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_24_000000_add_reception_order_request_id.php');
    $migration->down();
    $migration->up();
    expect($order->fresh()->getAttributes())->toBe($before);
    $uuid = (string) Str::uuid();
    DB::table('orders')->where('id', $order->id)->update(['reception_request_id' => $uuid]);
    try {
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
        expect(fn () => Order::factory()->create(['reception_request_id' => $uuid]))->toThrow(QueryException::class);
        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['amount_grosz' => -1]))->toThrow(QueryException::class);
        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['currency' => 'EUR']))->toThrow(QueryException::class);
        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['created_by_user_id' => 999999]))->toThrow(QueryException::class);
    } finally {
        DB::table('orders')->where('id', $order->id)->update(['reception_request_id' => null]);
    }
});
