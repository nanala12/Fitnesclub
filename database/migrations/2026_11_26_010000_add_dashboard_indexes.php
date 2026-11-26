<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'paid_at', 'id'], 'orders_paid_period_index');
            $table->index(['ordered_at', 'id'], 'orders_recent_index');
        });
        Schema::table('memberships', function (Blueprint $table): void {
            $table->index(['expires_on', 'starts_on', 'id'], 'memberships_expiry_window_index');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $table) => $table->dropIndex('memberships_expiry_window_index'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_paid_period_index');
            $table->dropIndex('orders_recent_index');
        });
    }
};
