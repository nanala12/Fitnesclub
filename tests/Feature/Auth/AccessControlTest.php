<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('panel routes enforce the role matrix', function (UserRole $role, string $route, int $status) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->get(route($route))->assertStatus($status);
})->with([
    'customer admin' => [UserRole::Customer, 'admin.dashboard', 403],
    'customer staff' => [UserRole::Customer, 'staff.dashboard', 403],
    'employee admin' => [UserRole::Employee, 'admin.dashboard', 403],
    'employee staff' => [UserRole::Employee, 'staff.dashboard', 200],
    'admin admin' => [UserRole::Admin, 'admin.dashboard', 200],
    'admin staff' => [UserRole::Admin, 'staff.dashboard', 200],
]);

test('guests must log in before reaching a panel', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'admin.dashboard', 'staff.dashboard', 'profile.edit']);

test('unverified users cannot bypass panel email verification', function (UserRole $role, string $route) {
    $this->actingAs(User::factory()->unverified()->create(['role' => $role]))
        ->get(route($route))->assertRedirect(route('verification.notice'));
})->with([
    [UserRole::Admin, 'admin.dashboard'],
    [UserRole::Employee, 'staff.dashboard'],
]);

test('the entry dashboard directs staff to their authorized panel', function (UserRole $role, string $destination) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get(route('dashboard'))->assertRedirect(route($destination));
})->with([[UserRole::Admin, 'admin.dashboard'], [UserRole::Employee, 'staff.dashboard']]);

test('all roles can only read and edit their own profile', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $other = User::factory()->create();
    expect(Gate::forUser($user)->allows('view', $user))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $user))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $other))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $other))->toBeFalse();

    $this->actingAs($user)->get(route('profile.edit', ['user_id' => $other->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $user->id));
    $this->patch(route('profile.update'), ['first_name' => 'Updated', 'last_name' => 'self', 'phone' => $user->phone, 'email' => $user->email])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBe('Updated self')->and($other->fresh()->name)->toBe($other->name);
})->with(UserRole::cases());

test('profile payload cannot change access fields', function (UserRole $role, string $field, mixed $value) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->patch(route('profile.update'), [
        'first_name' => 'Test', 'last_name' => 'User', 'phone' => $user->phone, 'email' => $user->email, $field => $value,
    ])->assertSessionHasErrors($field);
    expect($user->fresh()->role)->toBe($role)->and($user->fresh()->suspended_at)->toBeNull();
})->with(UserRole::cases())->with([
    ['role', 'admin'], ['suspended_at', '2026-11-12 10:00:00'], ['is_active', true], ['email_verified_at', '2026-11-12 10:00:00'], ['id', 999],
]);

test('suspension blocks existing sessions and invalidates authentication', function (UserRole $role, string $route) {
    $user = User::factory()->create(['role' => $role]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    User::whereKey($user->id)->update(['suspended_at' => now()]);
    Auth::forgetGuards();
    $this->get(route($route))->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Suspended')->where('auth.user', null));
    $this->assertGuest();
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
})->with([
    [UserRole::Customer, 'dashboard'], [UserRole::Employee, 'staff.dashboard'], [UserRole::Admin, 'admin.dashboard'],
]);

test('suspended accounts cannot log in with a correct password or start a two factor challenge', function (bool $twoFactor) {
    $factory = User::factory()->employee()->suspended();
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email')->assertSessionMissing('login.id');
    $this->assertGuest();
})->with([false, true]);

test('suspension between password and second factor rejects the pending challenge', function () {
    $user = User::factory()->employee()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHas('login.id', $user->id);
    $recoveryCodes = $user->getRawOriginal('two_factor_recovery_codes');
    $user->forceFill(['suspended_at' => now()])->save();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertForbidden()->assertSessionMissing('login.id');
    $this->assertGuest();
    expect($user->fresh()->getRawOriginal('two_factor_recovery_codes'))->toBe($recoveryCodes);
});

test('a valid second factor still authenticates an active user', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

test('post authentication checks reject a suspended user authenticated downstream', function () {
    $user = User::factory()->suspended()->create();
    Route::post('/_test/authenticate', function () use ($user) {
        Auth::login($user);

        return redirect('/dashboard');
    })->middleware('web');
    $this->post('/_test/authenticate')->assertForbidden();
    $this->assertGuest();
});

test('suspended users cannot mutate their profile', function () {
    $user = User::factory()->employee()->suspended()->create();
    $this->actingAs($user)->patchJson(route('profile.update'), ['name' => 'Unauthorized', 'email' => $user->email])
        ->assertForbidden();
    expect($user->fresh()->name)->toBe($user->name);
    $this->assertGuest();
});

test('a changed role is applied to the next request in an existing session', function () {
    $user = User::factory()->admin()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    User::whereKey($user->id)->update(['role' => UserRole::Employee->value]);
    Auth::forgetGuards();
    $this->get(route('admin.dashboard'))->assertForbidden();
});
