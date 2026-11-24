<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payments')->select(['provider', 'provider_session_id'])->whereNotNull('provider_session_id')->groupBy(['provider', 'provider_session_id'])->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Duplicate provider sessions require review; payment history cannot be discarded.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['provider_token TEXT', 'p24_environment VARCHAR(10)', 'p24_merchant_id INTEGER', 'p24_pos_id INTEGER', 'registration_started_at DATETIME', 'registration_finished_at DATETIME'] as $column) {
                DB::statement('ALTER TABLE payments ADD COLUMN '.$column);
            }
        } else {
            Schema::table('payments', function (Blueprint $table): void {
                $table->text('provider_token')->nullable();
                $table->string('p24_environment', 10)->nullable();
                $table->unsignedBigInteger('p24_merchant_id')->nullable();
                $table->unsignedBigInteger('p24_pos_id')->nullable();
                $table->dateTime('registration_started_at', 6)->nullable();
                $table->dateTime('registration_finished_at', 6)->nullable();
            });
        }
        Schema::table('payments', fn (Blueprint $table) => $table->unique(['provider', 'provider_session_id'], 'payments_provider_session_unique'));
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('registration_started_at')->exists()) {
            throw new LogicException('Cannot remove recorded payment registration history.');
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_provider_session_unique'));
        foreach (['provider_token', 'p24_environment', 'p24_merchant_id', 'p24_pos_id', 'registration_started_at', 'registration_finished_at'] as $column) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE payments DROP COLUMN '.$column);
            } else {
                Schema::table('payments', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
