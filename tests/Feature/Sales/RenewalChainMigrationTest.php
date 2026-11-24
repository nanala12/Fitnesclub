<?php

use App\Models\Membership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('renewal migration preserves historical constraints and refuses to rewrite old branches', function () {
    $root = Membership::factory()->create();
    $before = $root->refresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_23_040000_add_membership_renewal_chain.php');
    $migration->down();
    $first = Membership::factory()->create(['renewed_from_id' => $root->id, 'starts_on' => '2027-02-01', 'expires_on' => '2027-02-28']);
    $second = Membership::factory()->create(['renewed_from_id' => $root->id, 'starts_on' => '2027-03-01', 'expires_on' => '2027-03-31']);
    expect(fn () => $migration->up())->toThrow(LogicException::class, 'branches');
    expect(Schema::hasColumn('memberships', 'renewal_root_membership_id'))->toBeFalse();
    DB::table('memberships')->where('id', $second->id)->delete();
    $migration->up();
    expect($root->fresh()->getAttributes())->toBe($before)->and($first->fresh()->renewed_from_id)->toBe($root->id);
    expect(fn () => Membership::factory()->create(['renewed_from_id' => $root->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('memberships')->where('id', $root->id)->update(['entries_granted' => 8, 'entries_remaining' => -1]))->toThrow(QueryException::class);
    expect(fn () => DB::table('memberships')->where('id', $root->id)->update(['renewal_root_membership_id' => 999999]))->toThrow(QueryException::class);
    DB::table('orders')->where('id', $first->order_id)->update(['renewal_source_membership_id' => $root->id]);
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
    DB::table('orders')->where('id', $first->order_id)->update(['renewal_source_membership_id' => null]);
    if (DB::getDriverName() === 'sqlite') {
        expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    }
});
