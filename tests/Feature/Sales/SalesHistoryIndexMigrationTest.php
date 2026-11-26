<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('sales history indexes round trip without changing historical purchase rows', function () {
    $order = Order::factory()->create();
    $before = $order->fresh()->getRawOriginal();
    $indexes = ['orders_sales_channel_date_index' => ['sales_channel', 'ordered_at', 'id'],
        'orders_payment_method_date_index' => ['payment_method', 'ordered_at', 'id'],
        'orders_purchaser_phone_date_index' => ['purchaser_phone', 'ordered_at', 'id']];
    foreach ($indexes as $name => $columns) {
        expect(Schema::hasIndex('orders', $name))->toBeTrue()->and(Schema::hasIndex('orders', $columns))->toBeTrue();
    }
    $migration = require database_path('migrations/2026_11_26_000000_add_sales_history_indexes.php');
    $migration->down();
    foreach ($indexes as $name => $columns) {
        expect(Schema::hasIndex('orders', $name))->toBeFalse();
    }
    expect($order->fresh()->getRawOriginal())->toBe($before);
    $migration->up();
    foreach ($indexes as $name => $columns) {
        expect(Schema::hasIndex('orders', $name))->toBeTrue();
    }
    expect($order->fresh()->getRawOriginal())->toBe($before);
});
