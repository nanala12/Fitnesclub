<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the starter page renders for guests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user', null)
        );
});

test('the application health endpoint is available', function () {
    $this->get('/up')->assertOk();
});
