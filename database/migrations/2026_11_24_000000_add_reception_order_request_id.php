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
            DB::statement('ALTER TABLE orders ADD COLUMN reception_request_id VARCHAR(36)');
        } else {
            Schema::table('orders', fn (Blueprint $table) => $table->uuid('reception_request_id')->nullable());
        }
        Schema::table('orders', fn (Blueprint $table) => $table->unique('reception_request_id', 'orders_reception_request_unique'));
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('reception_request_id')->exists()) {
            throw new LogicException('Cannot discard reception sale idempotency history.');
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropUnique('orders_reception_request_unique'));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE orders DROP COLUMN reception_request_id');
        } else {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('reception_request_id'));
        }
    }
};
