<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('category', 30);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->index(['is_published', 'sort_order']);
        });
        Schema::create('price_list_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_group_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $type = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
            $integer = DB::getDriverName() === 'sqlite' ? "typeof(price_grosz) = 'integer' AND " : '';
            $table->rawColumn('price_grosz', "$type CHECK ({$integer}price_grosz >= 0 AND price_grosz <= 9223372036854775807)");
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->index(['price_list_group_id', 'is_published', 'sort_order'], 'price_services_group_visibility_order');
        });
        Schema::table('membership_plans', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                // Native ADD COLUMN preserves the existing monetary and plan CHECKs.
                $table->rawColumn('price_list_group_id', 'INTEGER REFERENCES price_list_groups(id) ON DELETE RESTRICT')->nullable();
            } else {
                $table->foreignId('price_list_group_id')->nullable()->constrained()->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropColumn('price_list_group_id');
            } else {
                $table->dropConstrainedForeignId('price_list_group_id');
            }
        });
        Schema::dropIfExists('price_list_services');
        Schema::dropIfExists('price_list_groups');
    }
};
