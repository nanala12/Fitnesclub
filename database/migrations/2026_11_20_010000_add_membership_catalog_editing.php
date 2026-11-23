<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(0);
        });
        Schema::create('membership_catalog_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('membership_catalog_states')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_catalog_states');
        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
