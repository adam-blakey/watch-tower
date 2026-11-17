<?php

use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('tells a super admin when no projects exist', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('home')->where('reason', 'no_projects'));
});

it('tells an admin when no projects exist', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->component('home')->where('reason', 'no_projects'));
});

it('tells an admin when they have not been given access to any project', function () {
    Project::factory()->create();

    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
        ->get(route('home'))
        ->assertInertia(fn ($page) => $page->component('home')->where('reason', 'not_assigned'));
});

it('opens the first project the admin can see', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $project->admins()->attach($admin->id);

    $this->actingAs($admin)
        ->get(route('home'))
        ->assertRedirect(route('projects.dashboard', $project));
});
