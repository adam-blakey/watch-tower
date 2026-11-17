<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('resets the password of the user with the given email', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('watch:reset-password', ['email' => 'ada@example.com'])
        ->expectsQuestion('New password', 'new-password-123')
        ->expectsQuestion('Confirm new password', 'new-password-123')
        ->assertSuccessful();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('fails for an unknown email', function () {
    $this->artisan('watch:reset-password', ['email' => 'nobody@example.com'])
        ->assertFailed();
});

it('keeps the old password when the confirmation does not match', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->artisan('watch:reset-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'new-password-123')
        ->expectsQuestion('Confirm new password', 'something-else')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($oldHash);
});
