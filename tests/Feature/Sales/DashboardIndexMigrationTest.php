<?php

use App\Models\Membership;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('dashboard query indexes round trip without rewriting sales or membership history', function () {
    $member = Membership::factory()->create();
    $before = [$member->fresh()->getRawOriginal(), $member->order->getRawOriginal()];
    $indexes = ['orders' => ['orders_paid_period_index', 'orders_recent_index'], 'memberships' => ['memberships_expiry_window_index']];
    foreach ($indexes as $table => $names) {
        foreach ($names as $name) {
            expect(Schema::hasIndex($table, $name))->toBeTrue();
        }
    }
    $migration = require database_path('migrations/2026_11_26_010000_add_dashboard_indexes.php');
    $migration->down();
    foreach ($indexes as $table => $names) {
        foreach ($names as $name) {
            expect(Schema::hasIndex($table, $name))->toBeFalse();
        }
    }
    $migration->up();
    foreach ($indexes as $table => $names) {
        foreach ($names as $name) {
            expect(Schema::hasIndex($table, $name))->toBeTrue();
        }
    }
    expect([$member->fresh()->getRawOriginal(), $member->order->fresh()->getRawOriginal()])->toBe($before);
});
