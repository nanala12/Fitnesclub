<?php

use App\Actions\Audit\RecordAudit;
use App\Actions\Employees\ManageEmployee;
use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function recordTestAudit(?User $actor = null, array $metadata = []): AuditLog
{
    return app(RecordAudit::class)->handle(AuditAction::EmployeeUpdated, 123, $actor,
        ['role' => 'employee', 'suspended' => false], ['role' => 'admin', 'suspended' => true], $metadata);
}

test('audit records explicit staff or system attribution and UTC time', function (?UserRole $role) {
    $this->travelTo(now()->setDate(2026, 6, 30)->setTime(22, 30, 0));
    $actor = $role === null ? null : User::factory()->create(['role' => $role]);
    $entry = recordTestAudit($actor);
    expect($entry->getAttribute('actor_user_id'))->toBe($actor?->id)
        ->and($entry->getAttribute('actor_type'))->toBe($role?->value ?? 'system')
        ->and($entry->occurred_at->format('Y-m-d H:i:sP'))->toBe('2026-06-30 22:30:00+00:00')
        ->and($entry->getAttribute('subject_type'))->toBe('user')
        ->and($entry->getAttribute('subject_id'))->toBe(123);
})->with([UserRole::Admin, UserRole::Employee, null]);

test('audit cannot infer a system actor from a customer or unsaved identity', function (string $kind) {
    $actor = $kind === 'customer' ? User::factory()->create() : new User;
    expect(fn () => recordTestAudit($actor))->toThrow(LogicException::class);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['customer', 'unsaved']);

test('audit keeps only allowed typed summaries and validated correlation context', function () {
    $requestId = (string) Str::uuid();
    $entry = app(RecordAudit::class)->handle(AuditAction::EmployeeUpdated, 123, null,
        ['role' => 'employee', 'suspended' => false, 'password' => 'sensitive-secret', 'email' => 'private@example.test'],
        ['role' => 'admin', 'suspended' => true, 'phone' => '+48600700800', 'nested' => ['token' => 'sensitive-secret']],
        ['request_id' => $requestId, 'changed_fields' => ['email', 'password', 'role', 'role', ['secret'], 'unknown'], 'token' => 'sensitive-secret', 'ip' => '192.0.2.1']);
    expect($entry->getAttribute('old_values'))->toBe(['role' => 'employee', 'suspended' => false])
        ->and($entry->getAttribute('new_values'))->toBe(['role' => 'admin', 'suspended' => true])
        ->and($entry->getAttribute('metadata'))->toBe(['changed_fields' => ['email', 'role'], 'request_id' => $requestId])
        ->and(json_encode($entry->getAttributes()))->not->toContain('sensitive-secret', 'private@example.test', '+48600700800', '192.0.2.1');
});

test('malformed values cannot smuggle secrets through allowed field names', function () {
    $entry = app(RecordAudit::class)->handle(AuditAction::EmployeeUpdated, 123, null,
        ['role' => 'secret@example.test', 'suspended' => ['password' => 'secret']],
        ['role' => ['secret'], 'suspended' => 'secret'], ['changed_fields' => 'secret', 'request_id' => 'secret@example.test']);
    expect($entry->getAttribute('old_values'))->toBe([])->and($entry->getAttribute('new_values'))->toBe([])
        ->and($entry->getAttribute('metadata'))->toBe(['changed_fields' => []]);
});

test('failed business transaction leaves neither domain change nor audit entry', function () {
    $actor = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    expect(function () use ($actor, $employee): void {
        DB::transaction(function () use ($actor, $employee): void {
            $employee->update(['first_name' => 'Temporary', 'last_name' => 'Change']);
            recordTestAudit($actor);
            throw new RuntimeException('Synthetic failure after audit insertion');
        });
    })->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('audit_logs', 0);
    expect($employee->fresh()->first_name)->toBeNull();
});

test('audit write failure prevents a real employee mutation', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit store failure'));
    try {
        expect(fn () => app(ManageEmployee::class)->save($admin, null, [
            'first_name' => 'Test', 'last_name' => 'Employee', 'email' => 'employee@example.test', 'phone' => '+48600700800', 'role' => 'employee',
        ]))->toThrow(RuntimeException::class, 'Synthetic audit store failure');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    } finally {
        AuditLog::flushEventListeners();
    }
});

test('standard model and bulk operations cannot mutate audit history even with events muted', function (string $operation) {
    $entry = recordTestAudit();
    $before = $entry->fresh()->getAttributes();
    $attempt = match ($operation) {
        'save' => fn () => $entry->forceFill(['action' => 'tampered'])->save(),
        'quiet-save' => fn () => $entry->forceFill(['action' => 'tampered'])->saveQuietly(),
        'delete' => fn () => $entry->delete(),
        'quiet-delete' => fn () => $entry->deleteQuietly(),
        'bulk-update' => fn () => AuditLog::query()->update(['action' => 'tampered']),
        'bulk-delete' => fn () => AuditLog::query()->delete(),
        'force-delete' => fn () => AuditLog::query()->forceDelete(),
        'increment' => fn () => AuditLog::query()->increment('subject_id'),
        'decrement' => fn () => AuditLog::query()->decrement('subject_id'),
        'increment-each' => fn () => AuditLog::query()->incrementEach(['subject_id' => 1]),
        'decrement-each' => fn () => AuditLog::query()->decrementEach(['subject_id' => 1]),
        'upsert' => fn () => AuditLog::query()->upsert([['id' => $entry->id, 'action' => 'tampered']], ['id'], ['action']),
        'truncate' => fn () => AuditLog::query()->truncate(),
    };
    expect($attempt)->toThrow(LogicException::class);
    expect($entry->fresh()->getAttributes())->toBe($before);
})->with(['save', 'quiet-save', 'delete', 'quiet-delete', 'bulk-update', 'bulk-delete', 'force-delete', 'increment', 'decrement', 'increment-each', 'decrement-each', 'upsert', 'truncate']);

test('only an active verified admin may read the audit page', function (string $kind) {
    if ($kind !== 'guest') {
        $user = User::factory()->create(['role' => in_array($kind, ['unverified', 'suspended', 'admin']) ? 'admin' : $kind]);
        if ($kind === 'unverified') {
            $user->forceFill(['email_verified_at' => null])->save();
        }
        if ($kind === 'suspended') {
            $user->forceFill(['suspended_at' => now()])->save();
        }
        $this->actingAs($user);
    }
    $response = $this->get(route('admin.audit.index'));
    match ($kind) {
        'guest' => $response->assertRedirect(route('login')),
        'unverified' => $response->assertRedirect(route('verification.notice')),
        'admin' => $response->assertOk(),
        default => $response->assertForbidden(),
    };
})->with(['guest', 'customer', 'employee', 'unverified', 'suspended', 'admin']);

test('even admins have no policy permission to mutate audit entries', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $entry = recordTestAudit();
    expect(Gate::forUser($user)->allows('view', $entry))->toBe($role === UserRole::Admin)
        ->and(Gate::forUser($user)->allows('create', AuditLog::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $entry))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $entry))->toBeFalse();
})->with(UserRole::cases());

test('audit viewer exposes safe changes current actor name and historical role without raw legacy data', function () {
    $actor = User::factory()->admin()->create(['name' => '<script>untrusted</script>']);
    $entry = recordTestAudit($actor, ['changed_fields' => ['role']]);
    // Simulate an old/imported row that predates the writer allowlist.
    DB::table('audit_logs')->where('id', $entry->id)->update(['new_values' => json_encode(['role' => 'admin', 'password' => 'legacy-secret', 'email' => 'private@example.test'])]);
    $actor->forceFill(['role' => UserRole::Employee])->save();
    $admin = User::factory()->admin()->create();
    $response = $this->actingAs($admin)->get(route('admin.audit.index'));
    $response->assertInertia(fn (Assert $page) => $page->component('admin/audit/Index')
        ->where('entries.data.0.actor.label', 'Administrator')->where('entries.data.0.actor.name', '<script>untrusted</script>')
        ->where('entries.data.0.changedFields', ['Rola'])->where('entries.data.0.changes.0.after', 'Administrator')
        ->missing('entries.data.0.new_values')->missing('entries.data.0.actor.email'));
    expect($response->getContent())->not->toContain('legacy-secret', 'private@example.test');
});

test('audit page paginates in deterministic newest first order and ignores foreign selectors', function () {
    $admin = User::factory()->admin()->create();
    foreach (range(1, 21) as $index) {
        recordTestAudit($admin);
    }
    $latest = AuditLog::query()->max('id');
    $this->actingAs($admin)->get(route('admin.audit.index', ['actor_user_id' => 999, 'subject_id' => 999]))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 21)->has('entries.data', 20)->where('entries.data.0.id', $latest));
    $this->get(route('admin.audit.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
});

test('empty audit history is real and contains no sample events', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 0)->where('entries.data', []));
});

test('audit mutations have no web endpoint', function (string $method) {
    $this->actingAs(User::factory()->admin()->create());
    recordTestAudit();
    $this->{$method}(route('admin.audit.index'), [])->assertStatus(405);
    $this->assertDatabaseCount('audit_logs', 1);
})->with(['post', 'patch', 'put', 'delete']);

test('audit actor lookup stays bounded with a fresh authorization read', function () {
    $admin = User::factory()->admin()->create();
    foreach (range(1, 20) as $index) {
        recordTestAudit(User::factory()->employee()->create());
    }
    $actorQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$actorQueries): void {
        if (preg_match('/select .*from ["`]?users["`]?/i', $query->sql)) {
            $actorQueries[] = $query->sql;
        }
    });
    $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
    expect($actorQueries)->toHaveCount(2); // Fresh administrator plus one eager actor query, regardless of 20 rows.
});

test('unknown historic event payloads do not expose their raw action or subject values', function () {
    $entry = recordTestAudit();
    DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'legacy-secret@example.test', 'subject_type' => 'private-secret']);
    $response = $this->actingAs(User::factory()->admin()->create())->get(route('admin.audit.index'));
    $response->assertInertia(fn (Assert $page) => $page->where('entries.data.0.action', 'Zdarzenie audytu')->where('entries.data.0.subjectLabel', 'Zapis historyczny'));
    expect($response->getContent())->not->toContain('legacy-secret@example.test', 'private-secret');
});

test('system events retain explicit attribution and safe correlation id in the viewer', function () {
    $requestId = (string) Str::uuid();
    recordTestAudit(null, ['request_id' => $requestId]);
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.actor', ['id' => null, 'name' => null, 'label' => 'System'])
        ->where('entries.data.0.requestId', $requestId));
});

test('invalid audit page numbers are rejected', function (mixed $page) {
    $this->actingAs(User::factory()->admin()->create())->getJson(route('admin.audit.index', ['page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
})->with([0, -1, 'invalid']);

test('audit refuses invalid subject identifiers within a transaction', function () {
    expect(fn () => app(RecordAudit::class)->handle(AuditAction::EmployeeUpdated, 0, null, null, null))->toThrow(LogicException::class);
    $this->assertDatabaseCount('audit_logs', 0);
});
