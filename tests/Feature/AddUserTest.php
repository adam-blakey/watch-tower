<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an admin with the given details', function () {
    $this->artisan('user:add', ['email' => 'ada@example.com', 'name' => 'Ada'])
        ->expectsQuestion('Password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'new-password-123')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->sole();

    expect($user->name)->toBe('Ada')
        ->and($user->role)->toBe(User::ROLE_ADMIN)
        ->and(Hash::check('new-password-123', $user->password))->toBeTrue();
});

it('creates a super admin with --super-admin', function () {
    $this->artisan('user:add', ['email' => 'ada@example.com', 'name' => 'Ada', '--super-admin' => true])
        ->expectsQuestion('Password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'new-password-123')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada@example.com')->value('role'))->toBe(User::ROLE_SUPER_ADMIN);
});

it('refuses an email that is already taken', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('user:add', ['email' => 'ada@example.com', 'name' => 'Ada'])
        ->expectsQuestion('Password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'new-password-123')
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.com')->count())->toBe(1);
});

it('refuses a password confirmation that does not match', function () {
    $this->artisan('user:add', ['email' => 'ada@example.com', 'name' => 'Ada'])
        ->expectsQuestion('Password', 'new-password-123')
        ->expectsQuestion('Confirm password', 'something-else')
        ->assertFailed();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse();
});
