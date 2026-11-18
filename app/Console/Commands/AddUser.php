<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an admin, like Settings → Admins does, e.g. to bootstrap an install.
 *
 * The user joins the oldest organization. `--super-admin` creates a super admin instead.
 */
#[Signature('user:add
    {email : The email address of the new user}
    {name : The display name of the new user}
    {--super-admin : Create a super admin instead of an admin}')]
#[Description('Create a new admin user')]
class AddUser extends Command
{
    public function handle(): int
    {
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'email' => $this->argument('email'),
                'name' => $this->argument('name'),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
            'role' => $this->option('super-admin') ? User::ROLE_SUPER_ADMIN : User::ROLE_ADMIN,
            'organization_id' => Organization::default()->id,
        ]);

        $this->components->info("Created {$user->role} [{$user->email}].");

        return self::SUCCESS;
    }
}
