<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a new password for a user, e.g. when they are locked out.
 *
 * Changing the password hash signs the user out of every existing session.
 */
#[Signature('user:reset-password {email : The email address of the user}')]
#[Description('Set a new password for a user')]
class ResetUserPassword extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error("No user with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $password = $this->secret('New password');
        $confirmation = $this->secret('Confirm new password');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user->update(['password' => $password]);

        $this->components->info("Password reset for [{$user->email}].");

        return self::SUCCESS;
    }
}
