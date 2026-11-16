<?php

use App\Enums\MediaPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check before DDL: MySQL does not roll schema changes back on failure.
        $owners = [];
        foreach (MediaPlacement::cases() as $placement) {
            $table = (new ($placement->model()))->getTable();
            foreach (DB::table($table)->whereNotNull($placement->column())->get(['id', $placement->column()]) as $row) {
                $mediaId = $row->{$placement->column()};
                if (isset($owners[$mediaId])) {
                    throw new RuntimeException('Shared CMS media must be resolved before applying media ownership.');
                }
                $owners[$mediaId] = ['owner_type' => $placement->value, 'owner_id' => $row->id];
            }
        }
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('owner_type', 30)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unique(['owner_type', 'owner_id']);
        });
        foreach ($owners as $mediaId => $owner) {
            DB::table('media_assets')->where('id', $mediaId)->update($owner);
        }
        Schema::create('media_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->dateTime('available_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['disk', 'path']);
            $table->index('available_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_deletions');
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropUnique(['owner_type', 'owner_id']);
            $table->dropColumn(['owner_type', 'owner_id']);
        });
    }
};
