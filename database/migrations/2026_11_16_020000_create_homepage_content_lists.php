<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_content_lists', function (Blueprint $table): void {
            $table->string('section', 30)->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        // Stable mutex rows also serialize the first insert into an empty list.
        DB::table('homepage_content_lists')->insert([
            ['section' => 'kafelki', 'revision' => 0],
            ['section' => 'karuzela', 'revision' => 0],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_content_lists');
    }
};
