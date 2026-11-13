<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

function registrationData(array $overrides = []): array
{
    return array_replace(['first_name' => 'Osoba', 'last_name' => 'Testowa', 'email' => 'new@example.test', 'phone' => '+48 600 700 800', 'password' => 'password', 'password_confirmation' => 'password'], $overrides);
}

test('registration normalizes contact data and always creates an active customer', function () {
    Notification::fake();
    $this->post(route('register.store'), registrationData(['email' => 'NEW@EXAMPLE.TEST', 'phone' => '0048 (600) 700-800']))
        ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
    $user = User::sole();
    expect($user->email)->toBe('new@example.test')->and($user->phone)->toBe('+48600700800')
        ->and($user->role)->toBe(UserRole::Customer)->and($user->suspended_at)->toBeNull()->and($user->email_verified_at)->toBeNull();
});

test('registration rejects duplicate registered contacts after normalization', function (string $field, string $value) {
    User::factory()->create(['email' => 'existing@example.test', 'phone' => '+48600700800']);
    $this->post(route('register.store'), registrationData(['phone' => '+48600700801', $field => $value]))
        ->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 1);
    $this->assertGuest();
})->with([['email', 'EXISTING@EXAMPLE.TEST'], ['phone', '0048 600-700-800'], ['phone', '+48 (600) 700 800']]);

test('registration refuses access field injection', function (string $field, mixed $value) {
    $this->post(route('register.store'), registrationData([$field => $value]))->assertSessionHasErrors($field);
    $this->assertDatabaseCount('users', 0);
    $this->assertGuest();
})->with([['role', 'admin'], ['role', 'employee'], ['suspended_at', '2026-11-12 10:00:00'], ['is_active', true], ['email_verified_at', '2026-11-12 10:00:00'], ['id', 100]]);

test('registration requires a canonicalizable international phone', function (mixed $phone) {
    $this->post(route('register.store'), registrationData(['phone' => $phone]))->assertSessionHasErrors('phone');
    $this->assertDatabaseCount('users', 0);
})->with([null, '', '600700800', '+0001234567', '+48600700800extension1', [['+48600700800']], '+1234567890123456']);

test('registered phone uniqueness is also enforced by the database', function () {
    User::factory()->create(['phone' => '+48600700800']);
    expect(fn () => User::factory()->create(['phone' => '0048 (600) 700-800']))->toThrow(UniqueConstraintViolationException::class);
});

test('existing accounts may temporarily have no phone without fictional backfill', function () {
    User::factory()->count(2)->create(['phone' => null]);
    $this->assertDatabaseCount('users', 2);
});

test('access fields are excluded from mass assignment', function () {
    $user = User::factory()->create();
    $user->fill(['role' => 'admin', 'suspended_at' => now(), 'is_active' => false])->save();
    expect($user->fresh()->role)->toBe(UserRole::Customer)->and($user->fresh()->suspended_at)->toBeNull();
});

test('registration is throttled', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register.store'), [])->assertSessionHasErrors();
    }
    $this->post(route('register.store'), [])->assertTooManyRequests();
});

test('password reset requests are throttled', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('password.email'), [])->assertSessionHasErrors();
    }
    $this->post(route('password.email'), [])->assertTooManyRequests();
});

test('password reset requests do not disclose whether an account exists', function () {
    Notification::fake();
    $user = User::factory()->create();
    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $known->assertRedirect(route('password.request'))->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'unknown@example.test'])
        ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

test('disabled registration is reflected on the login view', function () {
    config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
});

test('a contact collision after validation returns a field error instead of a database error', function (string $field) {
    User::creating(function () use ($field): void {
        DB::table('users')->insert([
            'name' => 'Competing test account',
            'email' => $field === 'email' ? 'new@example.test' : 'winner@example.test',
            'phone' => $field === 'phone' ? '+48600700800' : '+48600700801',
            'password' => Hash::make('test-only-password'),
        ]);
    });
    try {
        $this->post(route('register.store'), registrationData())->assertSessionHasErrors($field);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    } finally {
        User::flushEventListeners();
    }
})->with(['email', 'phone']);

test('malformed login identifiers are rejected without server errors', function () {
    $this->post(route('login.store'), ['email' => ['invalid'], 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});
