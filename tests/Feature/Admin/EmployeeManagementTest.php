<?php

use App\Actions\Employees\ManageEmployee;
use App\Actions\Employees\RecordEmployeeAudit;
use App\Enums\UserRole;
use App\Jobs\SendEmployeeInvitation;
use App\Jobs\SendProfileEmailVerification;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function employeeData(array $overrides = []): array
{
    return array_replace(['first_name' => 'Anna', 'last_name' => 'Testowa', 'email' => 'new-staff@example.test', 'phone' => '+48600700800', 'role' => 'employee'], $overrides);
}

function invitedEmployee(): User
{
    return User::factory()->employee()->unverified()->create(['staff_invited_at' => now()]);
}

test('only verified active admins can reach employee management endpoints', function (string $actor, string $method, string $route) {
    $employee = User::factory()->employee()->create();
    if ($actor !== 'guest') {
        $user = User::factory()->create(['role' => $actor]);
        $this->actingAs($user);
    }
    $response = $this->{$method}(route($route, ['employee' => $employee->id]), employeeData());
    if ($actor === 'guest') {
        $response->assertRedirect(route('login'));
    } else {
        $response->assertForbidden();
    }
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['guest', 'customer', 'employee'])->with([
    ['get', 'admin.employees.index'], ['get', 'admin.employees.create'], ['get', 'admin.employees.edit'],
    ['post', 'admin.employees.store'], ['patch', 'admin.employees.update'], ['patch', 'admin.employees.suspension'], ['post', 'admin.employees.invitation'],
]);

test('admin listing is paginated and exposes only team contact data', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->employee()->count(21)->create();
    $customer = User::factory()->create();
    $this->actingAs($admin)->get(route('admin.employees.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/employees/Index')->where('employees.total', 22)->has('employees.data', 20)
        ->missing('employees.data.0.password')->missing('employees.data.0.bootstrap_key')
        ->where('employees.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['id'] !== $customer->id)));
});

test('admin creates an invited staff account with the chosen allowed role and redacted audit', function (string $role) {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('admin.employees.store'), employeeData(['role' => $role, 'email' => 'NEW-STAFF@EXAMPLE.TEST', 'phone' => '0048 (600) 700-800']))->assertSessionHasNoErrors()->assertRedirect();
    $employee = User::where('email', 'new-staff@example.test')->sole();
    expect($employee->role->value)->toBe($role)->and($employee->phone)->toBe('+48600700800')
        ->and($employee->hasPendingStaffInvitation())->toBeTrue()->and($employee->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $employee->password))->toBeFalse();
    Queue::assertPushed(SendEmployeeInvitation::class, fn ($job) => $job->userId === $employee->id && $job->afterCommit === true);
    $audit = AuditLog::sole();
    expect($audit->getAttribute('actor_user_id'))->toBe($admin->id)->and($audit->getAttribute('subject_id'))->toBe($employee->id)
        ->and($audit->getAttribute('action'))->toBe('employee.created')
        ->and(json_encode($audit->getAttributes()))->not->toContain($employee->email, $employee->phone, $employee->password, 'Anna');
})->with(['employee', 'admin']);

test('admin cannot manage own access or a customer through staff routes', function (string $target) {
    $admin = User::factory()->admin()->create();
    $user = $target === 'own-account' ? $admin : User::factory()->create();
    $this->actingAs($admin)->get(route('admin.employees.edit', $user))->assertForbidden();
    $this->patch(route('admin.employees.update', $user), employeeData())->assertForbidden();
    $this->patch(route('admin.employees.suspension', $user), ['suspended' => true])->assertForbidden();
    $this->post(route('admin.employees.invitation', $user))->assertForbidden();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['own-account', 'customer']);

test('employee input rejects protected fields and unsupported roles', function (string $field, mixed $value) {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('admin.employees.store'), employeeData([$field => $value]))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    ['role', 'customer'], ['role', 'superadmin'], ['password', 'injected'], ['bootstrap_key', 'initial-admin'],
    ['email_verified_at', '2026-11-13'], ['suspended_at', '2026-11-13'], ['staff_invited_at', '2026-11-13'], ['staff_invitation_accepted_at', '2026-11-13'], ['id', 900],
]);

test('employee input validates required names and normalized unique contacts', function (string $field, mixed $value) {
    $admin = User::factory()->admin()->create(['email' => 'used@example.test', 'phone' => '+48600111222']);
    $this->actingAs($admin)->post(route('admin.employees.store'), employeeData([$field => $value]))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 1);
})->with([['first_name', ''], ['last_name', ''], ['email', 'USED@EXAMPLE.TEST'], ['phone', '0048 600 111 222'], ['phone', '600111222']]);

test('updates change staff role and contacts and require reverification without exposing raw diffs', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    $this->actingAs($admin)->patch(route('admin.employees.update', $employee), employeeData(['role' => 'admin']))->assertSessionHasNoErrors();
    expect($employee->fresh()->role)->toBe(UserRole::Admin)->and($employee->fresh()->email_verified_at)->toBeNull();
    Queue::assertPushed(SendProfileEmailVerification::class);
    expect(AuditLog::sole()->getAttribute('old_values')['role'])->toBe('employee')
        ->and(AuditLog::sole()->getAttribute('new_values')['role'])->toBe('admin');
});

test('pending invitation email changes invalidate old tokens and queue a new invitation', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $employee = invitedEmployee();
    Password::createToken($employee);
    $oldEmail = $employee->email;
    $this->actingAs($admin)->patch(route('admin.employees.update', $employee), employeeData())->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    Queue::assertPushed(SendEmployeeInvitation::class, fn ($job) => $job->emailHash === sha1('new-staff@example.test'));
});

test('a stale contact collision returns validation errors and creates no audit', function (string $field) {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $data = employeeData();
    User::factory()->create([$field => $data[$field]]);
    try {
        app(ManageEmployee::class)->save($admin, null, $data);
        $this->fail('A stale contact must be rejected by the database.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('audit_logs', 0);
    Queue::assertNothingPushed();
})->with(['email', 'phone']);

test('failed audit rolls back account creation and never dispatches an invitation', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $audit = Mockery::mock(RecordEmployeeAudit::class);
    $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
    $this->app->instance(RecordEmployeeAudit::class, $audit);
    expect(fn () => app(ManageEmployee::class)->save($admin, null, employeeData()))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('audit_logs', 0);
    Queue::assertNothingPushed();
});

test('suspension invalidates remembered login database sessions and setup tokens and blocks an existing authenticated session', function () {
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    $remember = $employee->remember_token;
    $this->post(route('login.store'), ['email' => $employee->email, 'password' => 'password'])->assertRedirect();
    DB::table('sessions')->insert(['id' => 'synthetic-staff-session', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
    Password::createToken($employee);
    app(ManageEmployee::class)->suspend($admin, $employee, true);
    expect($employee->fresh()->remember_token)->not->toBe($remember);
    $this->assertDatabaseMissing('sessions', ['user_id' => $employee->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $employee->email]);
    Auth::forgetGuards();
    $this->get(route('staff.dashboard'))->assertForbidden();
    $this->assertGuest();
    expect(AuditLog::sole()->getAttribute('actor_user_id'))->toBe($admin->id);
    app(ManageEmployee::class)->suspend($admin, $employee, true);
    $this->assertDatabaseCount('audit_logs', 1);
    app(ManageEmployee::class)->suspend($admin, $employee, false);
    expect($employee->fresh()->isSuspended())->toBeFalse();
    $this->post(route('login.store'), ['email' => $employee->email, 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('audit_logs', 2);
});

test('a revoked admin actor cannot complete a mutation with stale in memory privileges', function () {
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    User::whereKey($admin->id)->update(['role' => 'employee']);
    expect(fn () => app(ManageEmployee::class)->suspend($admin, $employee, true))->toThrow(AuthorizationException::class);
    expect($employee->fresh()->isSuspended())->toBeFalse();
});

test('audit entries cannot be mutated or deleted through models', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    app(ManageEmployee::class)->save($admin, null, employeeData());
    $audit = AuditLog::sole();
    expect(fn () => $audit->forceFill(['action' => 'tampered'])->save())->toThrow(LogicException::class);
    expect(fn () => $audit->delete())->toThrow(LogicException::class);
});

test('employee deletion is not exposed as an admin endpoint', function () {
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    $this->actingAs($admin)->delete(route('admin.employees.update', $employee))->assertStatus(405);
    expect($employee->fresh())->not->toBeNull();
});

test('audit failure rolls back existing account edits and suspension including token revocation', function (string $operation) {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->employee()->create();
    $before = $employee->fresh()->getAttributes();
    $token = Password::createToken($employee);
    $audit = Mockery::mock(RecordEmployeeAudit::class);
    $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('Synthetic audit failure'));
    $this->app->instance(RecordEmployeeAudit::class, $audit);
    $manage = app(ManageEmployee::class);
    expect(fn () => $operation === 'edit' ? $manage->save($admin, $employee, employeeData()) : $manage->suspend($admin, $employee, true))->toThrow(RuntimeException::class);
    expect($employee->fresh()->getAttributes())->toBe($before)->and(Password::tokenExists($employee, $token))->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
    Queue::assertNothingPushed();
})->with(['edit', 'suspend']);

test('staff cannot bypass suspension only lifecycle through self account deletion', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
    expect($user->fresh())->not->toBeNull();
    $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('canDeleteAccount', false));
})->with(['employee', 'admin']);

test('email edits revoke tokens for the locked current identity rather than a stale bound model', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $employee = invitedEmployee();
    $fresh = $employee->fresh();
    $fresh->update(['email' => 'intermediate@example.test']);
    Password::createToken($fresh);
    app(ManageEmployee::class)->save($admin, $employee, employeeData());
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'intermediate@example.test']);
    expect($employee->fresh()->email)->toBe('new-staff@example.test');
});
