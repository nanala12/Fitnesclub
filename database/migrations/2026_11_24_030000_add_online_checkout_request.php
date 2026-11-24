<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE orders ADD COLUMN checkout_request_id VARCHAR(36)');
            DB::statement('ALTER TABLE orders ADD COLUMN checkout_fingerprint VARCHAR(64)');
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->uuid('checkout_request_id')->nullable();
                $table->string('checkout_fingerprint', 64)->nullable();
            });
        }
        Schema::table('orders', fn (Blueprint $table) => $table->unique('checkout_request_id'));
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('checkout_request_id')->exists()) {
            throw new LogicException('Cannot remove used online checkout request history.');
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropUnique(['checkout_request_id']));
        foreach (['checkout_request_id', 'checkout_fingerprint'] as $column) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE orders DROP COLUMN '.$column);
            } else {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
