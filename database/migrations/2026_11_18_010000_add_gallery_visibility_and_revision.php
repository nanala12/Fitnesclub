<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_images', function (Blueprint $table): void {
            // Preserve visibility of existing images; the editor explicitly creates new drafts.
            $table->boolean('is_published')->default(true);
            $table->index(['gallery_category_id', 'is_published', 'sort_order']);
        });
        Schema::create('gallery_content_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('gallery_content_states')->insert(['id' => 1, 'revision' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_content_states');
        Schema::table('gallery_images', function (Blueprint $table): void {
            $table->dropIndex(['gallery_category_id', 'is_published', 'sort_order']);
            $table->dropColumn('is_published');
        });
    }
};
