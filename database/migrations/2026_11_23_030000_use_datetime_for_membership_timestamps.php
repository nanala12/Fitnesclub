<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // UTC session values are preserved; DATETIME supports visits beyond TIMESTAMP's 2038 limit.
            DB::statement('ALTER TABLE memberships MODIFY created_at DATETIME(6) NULL DEFAULT NULL, MODIFY updated_at DATETIME(6) NULL DEFAULT NULL');
        }
        // SQLite already stores these timestamps as datetime without an epoch limit.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['created_at', 'updated_at'] as $column) {
            if (DB::table('memberships')->where($column, '<', '1970-01-01 00:00:01')->orWhere($column, '>', '2038-01-19 03:14:07')->exists()) {
                throw new LogicException('Cannot restore TIMESTAMP columns without losing membership history outside their supported date range.');
            }
        }
        DB::statement('ALTER TABLE memberships MODIFY created_at TIMESTAMP(6) NULL DEFAULT NULL, MODIFY updated_at TIMESTAMP(6) NULL DEFAULT NULL');
    }
};
