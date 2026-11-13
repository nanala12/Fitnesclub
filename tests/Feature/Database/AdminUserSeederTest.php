<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['bootstrap.admin' => [
        'name' => 'Bootstrap Test Operator',
        'email' => 'BOOTSTRAP@EXAMPLE.TEST',
        'phone' => '0048 (600) 700-800',
        'password' => 'Test-only!Secret42',
    ]]);
});

test('explicit bootstrap creates a verified administrator with normalized contacts and a hashed secret', function () {
    Notification::fake();
    $this->seed(AdminUserSeeder::class);
    $user = User::sole();
    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->email)->toBe('bootstrap@example.test')
        ->and($user->phone)->toBe('+48600700800')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->suspended_at)->toBeNull()
        ->and($user->getAttribute('bootstrap_key'))->toBe('initial-admin')
        ->and($user->password)->not->toBe(config('bootstrap.admin.password'))
        ->and(Hash::check(config('bootstrap.admin.password'), $user->password))->toBeTrue()
        ->and($user->toArray())->not->toHaveKeys(['password', 'bootstrap_key']);
    Notification::assertNothingSent();
    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
});

test('rerunning bootstrap preserves all existing data even with changed or removed credentials', function () {
    $this->seed(AdminUserSeeder::class);
    $user = User::sole();
    $user->forceFill(['name' => 'Updated operator', 'phone' => '+48600700801', 'password' => 'Changed-only!Secret42', 'suspended_at' => now()])->save();
    $before = $user->fresh()->getAttributes();
    config(['bootstrap.admin.name' => null, 'bootstrap.admin.phone' => null, 'bootstrap.admin.password' => null]);
    $this->seed(AdminUserSeeder::class);
    expect(User::sole()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('users', 1);
});

test('email collision never adopts an existing account even when it is already an admin', function (string $role) {
    $existing = User::factory()->create(['email' => 'bootstrap@example.test', 'role' => $role]);
    $before = $existing->fresh()->getAttributes();
    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(RuntimeException::class, 'collides');
    expect($existing->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('users', 1);
})->with(['customer', 'employee', 'admin']);

test('normalized phone collision does not change either identity', function () {
    $existing = User::factory()->create(['phone' => '+48600700800']);
    $before = $existing->fresh()->getAttributes();
    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(RuntimeException::class, 'collides');
    expect($existing->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('users', 1);
});

test('changed bootstrap email cannot create a second initial administrator', function () {
    $this->seed(AdminUserSeeder::class);
    config(['bootstrap.admin.email' => 'different@example.test']);
    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(RuntimeException::class, 'Manual authorized review');
    $this->assertDatabaseCount('users', 1);
});

test('bootstrap cannot restore a deliberately revoked administrator role', function () {
    $this->seed(AdminUserSeeder::class);
    User::sole()->forceFill(['role' => UserRole::Customer])->save();
    expect(fn () => $this->seed(AdminUserSeeder::class))->toThrow(RuntimeException::class, 'Manual authorized review');
    expect(User::sole()->role)->toBe(UserRole::Customer);
});

test('invalid or missing bootstrap configuration creates no account and exposes no secret', function (string $field, mixed $value) {
    config(["bootstrap.admin.{$field}" => $value]);
    try {
        $this->seed(AdminUserSeeder::class);
        $this->fail('Invalid bootstrap configuration must be rejected.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('Test-only!Secret42', 'bootstrap@example.test', '0048');
    }
    $this->assertDatabaseCount('users', 0);
})->with([
    ['email', null], ['email', 'invalid'], ['name', null], ['phone', null],
    ['phone', '600700800'], ['password', null], ['password', 'short'],
    ['password', 'onlylowercasecharacters'], ['password', ['malformed']],
]);

test('bootstrap marker is unique at the database level', function () {
    $this->seed(AdminUserSeeder::class);
    expect(fn () => User::factory()->create(['bootstrap_key' => 'initial-admin']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('public mass assignment cannot claim a bootstrap marker', function () {
    $user = User::factory()->create();
    $user->fill(['bootstrap_key' => 'initial-admin'])->save();
    expect($user->fresh()->getAttribute('bootstrap_key'))->toBeNull();
});

test('default seeding never implicitly bootstraps an administrator', function () {
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseCount('users', 0);
});
