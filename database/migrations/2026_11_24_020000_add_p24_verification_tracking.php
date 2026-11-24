<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payments')->select(['provider', 'p24_environment', 'p24_merchant_id', 'provider_transaction_id'])
            ->whereNotNull('p24_environment')->whereNotNull('p24_merchant_id')->whereNotNull('provider_transaction_id')
            ->groupBy(['provider', 'p24_environment', 'p24_merchant_id', 'provider_transaction_id'])->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Duplicate provider transactions require review without discarding history.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['verification_attempts INTEGER NOT NULL DEFAULT 0', 'verification_last_attempt_at DATETIME', 'verification_failure_code VARCHAR(64)'] as $column) {
                DB::statement('ALTER TABLE payments ADD COLUMN '.$column);
            }
        } else {
            Schema::table('payments', function (Blueprint $table): void {
                $table->unsignedInteger('verification_attempts')->default(0);
                $table->dateTime('verification_last_attempt_at', 6)->nullable();
                $table->string('verification_failure_code', 64)->nullable();
            });
        }
        Schema::table('payments', fn (Blueprint $table) => $table->unique(['provider', 'p24_environment', 'p24_merchant_id', 'provider_transaction_id'], 'payments_p24_transaction_unique'));
    }

    public function down(): void
    {
        if (DB::table('payments')->where('verification_attempts', '>', 0)->exists()) {
            throw new LogicException('Cannot remove recorded payment verification history.');
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_p24_transaction_unique'));
        foreach (['verification_attempts', 'verification_last_attempt_at', 'verification_failure_code'] as $column) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE payments DROP COLUMN '.$column);
            } else {
                Schema::table('payments', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
