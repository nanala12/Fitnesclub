<?php

use App\Models\Membership;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('timestamp conversion round trip preserves UTC fractional instants and other membership data', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('SQLite datetime columns need no conversion.');
    }
    $membership = Membership::factory()->create(['created_at' => '2026-11-23 12:34:56.123456', 'updated_at' => '2026-11-23 13:45:12.654321']);
    $before = $membership->refresh()->getAttributes();
    $indexes = Schema::getIndexes('memberships');
    $migration = require database_path('migrations/2026_11_23_030000_use_datetime_for_membership_timestamps.php');
    $migration->down();
    expect($membership->fresh()->getAttributes())->toBe($before);
    $migration->up();
    expect($membership->fresh()->getAttributes())->toBe($before)->and(Schema::getIndexes('memberships'))->toBe($indexes);
});
