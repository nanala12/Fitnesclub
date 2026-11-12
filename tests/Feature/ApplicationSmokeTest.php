<?php

use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('the starter page renders for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user', null)
            ->where('canRegister', true)
            ->where('name', config('app.name'))
            ->where('businessTimezone', 'Europe/Warsaw')
            ->where('sidebarOpen', true)
        );
});

test('the home page reflects disabled registration from server configuration', function () {
    config(['fortify.features' => array_values(array_diff(
        config('fortify.features'),
        [Features::registration()],
    ))]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
});

test('shared props reflect the application name and sidebar preference', function () {
    config(['app.name' => 'ActiveFit Test']);

    $this->withUnencryptedCookie('sidebar_state', 'false')
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'ActiveFit Test')
            ->where('sidebarOpen', false)
        );
});

test('the application health endpoint is available', function () {
    $this->get('/up')->assertOk();
});
