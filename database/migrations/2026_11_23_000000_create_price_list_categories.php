<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 180);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->index(['is_published', 'sort_order']);
        });
        $categories = ['gym' => 'Siłownia', 'training' => 'Trening personalny', 'therapy' => 'Fizjoterapia', 'care' => 'Zabiegi', 'other' => 'Pozostałe'];
        foreach (DB::table('price_list_groups')->distinct()->pluck('category') as $key) {
            $categories[$key] ??= $key;
        }
        foreach ($categories as $key => $name) {
            DB::table('price_list_categories')->insert(['key' => $key, 'name' => $name, 'is_published' => true, 'sort_order' => array_search($key, array_keys($categories), true), 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_categories');
    }
};
