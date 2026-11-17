<?php

use App\Models\User;

it('lets a user keep their own email but not take someone else\'s', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $other = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('settings.profile.update'), ['name' => 'New Name', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name');

    $this->actingAs($user)
        ->patch(route('settings.profile.update'), ['name' => 'New Name', 'email' => $other->email])
        ->assertSessionHasErrors('email');
});
