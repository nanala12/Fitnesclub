<?php

use App\Models\DocumentDelivery;
use App\Models\DocumentDeliveryAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

afterEach(function () {
    DB::table('document_delivery_attempts')->delete();
    foreach (DB::table('document_deliveries')->orderByDesc('id')->pluck('id') as $id) {
        DB::table('document_deliveries')->where('id', $id)->delete();
    }
});

test('delivery migration backfills attempt history and preserves original rows and restrictive references', function () {
    $delivery = DocumentDelivery::factory()->create();
    $attempt = DocumentDeliveryAttempt::factory()->create(['document_delivery_id' => $delivery->id, 'attempt_number' => 3]);
    $fields = ['id', 'membership_id', 'kind', 'recipient_email', 'scheduled_for', 'status', 'sent_at', 'storage_path', 'idempotency_key', 'created_at', 'updated_at'];
    $before = array_intersect_key($delivery->refresh()->getAttributes(), array_flip($fields));
    $migration = require database_path('migrations/2026_11_24_040000_add_document_delivery_processing.php');
    $migration->down();
    $migration->up();
    expect(array_intersect_key($delivery->fresh()->getAttributes(), array_flip($fields)))->toBe($before)->and($delivery->fresh()->attempts_count)->toBe(3)
        ->and(DocumentDeliveryAttempt::findOrFail($attempt->id)->attempt_number)->toBe(3);
    $request = (string) Str::uuid();
    $child = DocumentDelivery::factory()->create(['root_delivery_id' => $delivery->id, 'request_id' => $request]);
    expect(fn () => DocumentDelivery::factory()->create(['request_id' => $request]))->toThrow(QueryException::class);
    expect(fn () => DB::table('document_deliveries')->where('id', $delivery->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('document_deliveries')->where('id', $child->id)->update(['membership_id' => 999999]))->toThrow(QueryException::class);
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'history');
});
