<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('memberships')->select('renewed_from_id')->whereNotNull('renewed_from_id')->groupBy('renewed_from_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Existing renewal branches require review; history cannot be silently rewritten.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE memberships ADD COLUMN renewal_root_membership_id INTEGER REFERENCES memberships(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE orders ADD COLUMN renewal_source_membership_id INTEGER REFERENCES memberships(id) ON DELETE RESTRICT');
        } else {
            Schema::table('memberships', function (Blueprint $table): void {
                $table->foreignId('renewal_root_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            });
        }
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('renewal_source_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            });
        }
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique('renewed_from_id', 'membership_renewal_next_unique');
            $table->index('renewal_root_membership_id', 'membership_renewal_root_index');
        });
        if (DB::getDriverName() === 'mysql' && Schema::hasIndex('memberships', 'memberships_renewed_from_id_foreign')) {
            Schema::table('memberships', fn (Blueprint $table) => $table->dropIndex('memberships_renewed_from_id_foreign'));
        }
    }

    public function down(): void
    {
        if (DB::table('memberships')->whereNotNull('renewal_root_membership_id')->exists() || DB::table('orders')->whereNotNull('renewal_source_membership_id')->exists()) {
            throw new LogicException('Cannot remove recorded renewal lineage. Preserve membership history.');
        }
        if (DB::getDriverName() === 'mysql') {
            // InnoDB can replace the old implicit FK index with the new unique index.
            // Restore its support before dropping that unique index, and remove new FKs first.
            Schema::table('orders', fn (Blueprint $table) => $table->dropForeign(['renewal_source_membership_id']));
            Schema::table('memberships', function (Blueprint $table): void {
                $table->dropForeign(['renewal_root_membership_id']);
                $table->index('renewed_from_id', 'memberships_renewed_from_id_foreign');
            });
        }
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('membership_renewal_next_unique');
            $table->dropIndex('membership_renewal_root_index');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE orders DROP COLUMN renewal_source_membership_id');
            DB::statement('ALTER TABLE memberships DROP COLUMN renewal_root_membership_id');
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('renewal_source_membership_id');
            });
            Schema::table('memberships', function (Blueprint $table): void {
                $table->dropColumn('renewal_root_membership_id');
            });
        }
    }
};
