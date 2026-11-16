<?php

use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('signs other sessions out when you change your password, but keeps the current one', function () {
    $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $otherDeviceSession = ['password_hash_web' => $user->getAuthPassword()];

    $this->actingAs($user)
        ->patch(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('settings.profile.show'))->assertOk();

    $this->withSession($otherDeviceSession)
        ->get(route('settings.profile.show'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('signs an admin out when a super admin resets their password', function () {
    $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $oldPasswordHash = $admin->getAuthPassword();

    $this->actingAs($superAdmin)
        ->patch(route('settings.admins.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin->fresh())
        ->withSession(['password_hash_web' => $oldPasswordHash])
        ->get(route('settings.profile.show'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
