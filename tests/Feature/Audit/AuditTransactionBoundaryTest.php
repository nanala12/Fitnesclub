<?php

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

test('audit refuses writes outside the caller business transaction', function () {
    expect(DB::transactionLevel())->toBe(0);
    expect(fn () => app(RecordAudit::class)->handle(AuditAction::EmployeeUpdated, 123, null, null, null))
        ->toThrow(LogicException::class, 'business transaction');
    $this->assertDatabaseCount('audit_logs', 0);
});
