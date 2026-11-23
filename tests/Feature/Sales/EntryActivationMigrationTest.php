<?php

use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('activation migration reverses and reapplies preserving historical data indexes and dependent foreign keys', function () {
    $delivery = DocumentDelivery::factory()->create();
    $old = $delivery->membership;
    $old->forceFill(['starts_on' => '2026-10-01', 'expires_on' => '2026-10-31', 'activated_at' => now(), 'activated_by_user_id' => User::factory()->employee()->create()->id])->save();
    $before = $old->refresh()->getAttributes();
    $indexes = Schema::getIndexes('memberships');
    $migration = require database_path('migrations/2026_11_23_020000_allow_non_expiring_entry_activation.php');
    $entryOrder = Order::factory()->paidAtReception()->create(['membership_plan_id' => MembershipPlan::factory()->entries(8)->create()->id]);
    $entry = Membership::factory()->for($entryOrder, 'order')->create();
    try {
        $migration->down();
        expect($old->fresh()->getAttributes())->toBe($before)->and($delivery->fresh()->membership->id)->toBe($old->id);
        expect(fn () => DB::table('memberships')->where('id', $entry->id)->update(['starts_on' => '2026-11-23', 'activated_at' => now(), 'activated_by_user_id' => $old->activated_by_user_id]))->toThrow(QueryException::class);
        $migration->up();
        DB::table('memberships')->where('id', $entry->id)->update(['starts_on' => '2026-11-23', 'activated_at' => now(), 'activated_by_user_id' => $old->activated_by_user_id]);
        expect($entry->fresh()->expires_on)->toBeNull()->and($entry->fresh()->starts_on->toDateString())->toBe('2026-11-23')
            ->and($old->fresh()->getAttributes())->toBe($before)->and(Schema::getIndexes('memberships'))->toBe($indexes)
            ->and($delivery->fresh()->membership->id)->toBe($old->id);
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve their history');
        expect($entry->fresh()->starts_on->toDateString())->toBe('2026-11-23');
        expect(fn () => DB::table('memberships')->where('id', $old->id)->update(['expires_on' => null]))->toThrow(QueryException::class);
        expect(fn () => DB::table('memberships')->where('id', $entry->id)->update(['entries_remaining' => -1]))->toThrow(QueryException::class);
        expect(fn () => DB::table('memberships')->where('id', $entry->id)->update(['activated_by_user_id' => null]))->toThrow(QueryException::class);
        if (DB::getDriverName() === 'sqlite') {
            expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        }
    } finally {
        // Dispose only this isolated test fixture so DatabaseMigrations can roll back its schema.
        DB::table('memberships')->where('id', $entry->id)->delete();
    }
});
