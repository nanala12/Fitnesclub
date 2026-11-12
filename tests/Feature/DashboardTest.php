<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected to login before reading the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('unverified accounts must verify their email before reading the dashboard', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('the dashboard shows only the current users account data', function () {
    $user = User::factory()->withTwoFactor()->create([
        'created_at' => '2026-06-30 22:30:00',
    ]);
    User::factory()->create();

    $user->setAttribute('internal_note', 'Private staff note');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('accountCreatedAt', $user->created_at->toISOString())
            ->where('auth.user', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at->toISOString(),
            ])
            ->where('businessTimezone', 'Europe/Warsaw')
            ->missing('users')
        );
});

test('the dashboard cannot be switched to another user through query parameters', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['user_id' => $other->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.email', $user->email)
        );
});
