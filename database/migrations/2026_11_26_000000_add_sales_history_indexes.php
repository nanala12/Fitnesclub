<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['sales_channel', 'ordered_at', 'id'], 'orders_sales_channel_date_index');
            $table->index(['payment_method', 'ordered_at', 'id'], 'orders_payment_method_date_index');
            $table->index(['purchaser_phone', 'ordered_at', 'id'], 'orders_purchaser_phone_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_sales_channel_date_index');
            $table->dropIndex('orders_payment_method_date_index');
            $table->dropIndex('orders_purchaser_phone_date_index');
        });
    }
};
