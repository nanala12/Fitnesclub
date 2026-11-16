<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('valid pending staff links render the provided account activation design', function (string $role, string $label) {
    $user = User::factory()->unverified()->create(['role' => $role, 'staff_invited_at' => now()]);
    $password = $user->password;
    $token = Password::createToken($user);
    $this->get(route('password.reset', ['token' => $token, 'email' => strtoupper($user->email)]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ActivateAccount')->where('email', $user->email)
            ->where('token', $token)->where('panelLabel', $label)->where('isAdminInvitation', $role === 'admin')
            ->has('passwordRules')->missing('user')->missing('password'));
    expect($user->fresh()->password)->toBe($password)->and($user->fresh()->hasPendingStaffInvitation())->toBeTrue()
        ->and($user->fresh()->email_verified_at)->toBeNull()->and(Password::tokenExists($user, $token))->toBeTrue();
})->with([['employee', 'Panel pracownika'], ['admin', 'Panel administratora']]);

test('invalid or unrelated reset links do not reveal staff invitation context', function (string $state) {
    $user = User::factory()->employee()->unverified()->create(['staff_invited_at' => now()]);
    $token = Password::createToken($user);
    $email = $user->email;
    if ($state === 'invalid') {
        $token = 'invalid-token';
    }
    if ($state === 'expired') {
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
    }
    if ($state === 'suspended') {
        $user->forceFill(['suspended_at' => now()])->save();
    }
    if ($state === 'accepted') {
        $user->forceFill(['staff_invitation_accepted_at' => now()])->save();
    }
    if ($state === 'customer') {
        $user->forceFill(['role' => 'customer'])->save();
    }
    if ($state === 'other-email') {
        $email = 'other@example.test';
    }
    $this->get(route('password.reset', ['token' => $token, 'email' => $email, 'activation' => 1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ResetPassword')->missing('isAdminInvitation')->missing('panelLabel'));
})->with(['invalid', 'expired', 'suspended', 'accepted', 'customer', 'other-email']);

test('ordinary customer password resets retain their existing screen', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertInertia(fn (Assert $page) => $page
        ->component('auth/ResetPassword')->where('email', $user->email)->missing('isAdminInvitation'));
});

test('malformed email query data does not cause a reset view server error', function () {
    $this->get(route('password.reset', ['token' => 'invalid-token', 'email' => ['malformed']]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/ResetPassword')->where('email', ''));
});

test('activation validation failures keep the invitation screen and do not activate the account', function () {
    $user = User::factory()->employee()->unverified()->create(['staff_invited_at' => now()]);
    $token = Password::createToken($user);
    $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
    $this->from($url)->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'New-test!Password42', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors('password')->assertRedirect($url);
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('auth/ActivateAccount'));
    expect($user->fresh()->hasPendingStaffInvitation())->toBeTrue()->and(Password::tokenExists($user, $token))->toBeTrue();
});
