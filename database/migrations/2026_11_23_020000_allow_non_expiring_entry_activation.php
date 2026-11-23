<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const string OLD_EXPIRY = '(starts_on IS NULL AND expires_on IS NULL) OR (starts_on IS NOT NULL AND expires_on IS NOT NULL AND expires_on >= starts_on)';

    private const string NEW_EXPIRY = '(starts_on IS NULL AND expires_on IS NULL) OR (starts_on IS NOT NULL AND ((expires_on IS NOT NULL AND expires_on >= starts_on) OR (expires_on IS NULL AND entries_granted IS NOT NULL)))';

    private const string OLD_ACTIVATION = '(activated_at IS NULL AND activated_by_user_id IS NULL) OR (activated_at IS NOT NULL AND activated_by_user_id IS NOT NULL AND starts_on IS NOT NULL AND expires_on IS NOT NULL)';

    private const string NEW_ACTIVATION = '(activated_at IS NULL AND activated_by_user_id IS NULL) OR (activated_at IS NOT NULL AND activated_by_user_id IS NOT NULL AND starts_on IS NOT NULL AND (expires_on IS NOT NULL OR entries_granted IS NOT NULL))';

    public function up(): void
    {
        $this->replaceChecks(self::OLD_EXPIRY, self::NEW_EXPIRY, self::OLD_ACTIVATION, self::NEW_ACTIVATION);
    }

    public function down(): void
    {
        if (DB::table('memberships')->whereNotNull('starts_on')->whereNull('expires_on')->exists()) {
            throw new LogicException('Cannot restore expiry requirements while non-expiring activated entry passes exist. Preserve their history.');
        }
        $this->replaceChecks(self::NEW_EXPIRY, self::OLD_EXPIRY, self::NEW_ACTIVATION, self::OLD_ACTIVATION);
    }

    private function replaceChecks(string $oldExpiry, string $expiry, string $oldActivation, string $activation): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE memberships DROP CHECK memberships_expires_on_check_0, DROP CHECK memberships_activated_at_check_0,
                ADD CONSTRAINT memberships_expires_on_check_0 CHECK ($expiry),
                ADD CONSTRAINT memberships_activated_at_check_0 CHECK ($activation)");

            return;
        }
        if (DB::getDriverName() !== 'sqlite') {
            throw new LogicException('Membership activation migration supports MySQL and SQLite only.');
        }
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'memberships')->value('sql');
        if (! is_string($sql) || ! str_contains($sql, $oldExpiry) || ! str_contains($sql, $oldActivation)) {
            throw new LogicException('Unexpected membership schema; refusing to replace unrelated constraints.');
        }
        $create = str_replace([$oldExpiry, $oldActivation], [$expiry, $activation], $sql);
        $create = preg_replace('/^create table "memberships"/i', 'create table "memberships_activation"', $create, 1, $replaced);
        if ($create === null || $replaced !== 1) {
            throw new LogicException('Unexpected SQLite table definition.');
        }
        $indexes = DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'memberships')->whereNotNull('sql')->pluck('sql');
        Schema::withoutForeignKeyConstraints(function () use ($create, $indexes): void {
            DB::transaction(function () use ($create, $indexes): void {
                DB::statement($create);
                DB::statement('INSERT INTO memberships_activation SELECT * FROM memberships');
                DB::statement('DROP TABLE memberships');
                DB::statement('ALTER TABLE memberships_activation RENAME TO memberships');
                foreach ($indexes as $index) {
                    DB::statement($index);
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('Membership migration would violate historical foreign keys.');
                }
            });
        });
    }
};
