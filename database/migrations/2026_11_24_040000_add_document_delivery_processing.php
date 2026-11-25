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
            foreach (['root_delivery_id INTEGER REFERENCES document_deliveries(id) ON DELETE RESTRICT',
                'requested_by_user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT', 'request_id VARCHAR(36)',
                'attempts_count INTEGER NOT NULL DEFAULT 0', 'processing_token VARCHAR(36)', 'processing_started_at DATETIME',
                'queued_at DATETIME', 'last_error_code VARCHAR(100)', 'needs_review BOOLEAN NOT NULL DEFAULT 0'] as $column) {
                DB::statement('ALTER TABLE document_deliveries ADD COLUMN '.$column);
            }
        } else {
            Schema::table('document_deliveries', function (Blueprint $table): void {
                $table->foreignId('root_delivery_id')->nullable()->constrained('document_deliveries')->restrictOnDelete();
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->uuid('request_id')->nullable();
                $table->unsignedInteger('attempts_count')->default(0);
                $table->uuid('processing_token')->nullable();
                $table->dateTime('processing_started_at', 6)->nullable();
                $table->dateTime('queued_at', 6)->nullable();
                $table->string('last_error_code', 100)->nullable();
                $table->boolean('needs_review')->default(false);
            });
        }
        Schema::table('document_deliveries', function (Blueprint $table): void {
            $table->unique('request_id', 'deliveries_request_unique');
            $table->index(['status', 'processing_started_at'], 'deliveries_processing_index');
        });
        DB::statement('UPDATE document_deliveries SET attempts_count = COALESCE((SELECT MAX(attempt_number) FROM document_delivery_attempts WHERE document_delivery_id = document_deliveries.id), 0)');
        DB::table('document_deliveries')->where('status', 'processing')->update(['needs_review' => true, 'last_error_code' => 'delivery_interrupted']);
    }

    public function down(): void
    {
        if (DB::table('document_deliveries')->where('attempts_count', '>', 0)->orWhereNotNull('request_id')->orWhere('needs_review', true)->exists()) {
            throw new LogicException('Cannot remove used document delivery processing history.');
        }
        Schema::table('document_deliveries', function (Blueprint $table): void {
            $table->dropUnique('deliveries_request_unique');
            $table->dropIndex('deliveries_processing_index');
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['root_delivery_id']);
                $table->dropForeign(['requested_by_user_id']);
            }
        });
        foreach (['root_delivery_id', 'requested_by_user_id', 'request_id', 'attempts_count', 'processing_token', 'processing_started_at', 'queued_at', 'last_error_code', 'needs_review'] as $column) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('ALTER TABLE document_deliveries DROP COLUMN '.$column);
            } else {
                Schema::table('document_deliveries', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
