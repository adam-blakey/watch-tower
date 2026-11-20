<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('shares the configured app name and icon with Inertia', function () {
    config(['app.name' => 'Acme Monitor', 'app.icon' => 'https://cdn.example.com/logo.svg']);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page
            ->where('name', 'Acme Monitor')
            ->where('icon', 'https://cdn.example.com/logo.svg'));
});

it('uses the configured icon for the favicon links', function () {
    config(['app.icon' => 'https://cdn.example.com/logo.svg']);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee('<link rel="icon" href="https://cdn.example.com/logo.svg">', false)
        ->assertDontSee('/favicon.ico', false);
});

it('falls back to the bundled favicon when no icon is configured', function () {
    config(['app.icon' => null]);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee('/favicon.ico', false);
});
