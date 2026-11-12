<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kalinga:create-admin')]
#[Description('Create the first KalingaAlert system administrator')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        if (User::query()->where('role', UserRole::SystemAdministrator->value)->where('is_active', true)->exists()) {
            $this->components->error('An active system administrator already exists. Manage staff from the application.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = mb_strtolower(trim((string) $this->ask('Administrator email')));

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('A name and valid email address are required.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('That email address already belongs to an account.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->components->error('Passwords must match and contain at least 12 characters.');

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::SystemAdministrator->value,
            'is_active' => true,
        ]);

        $this->components->info('System administrator account created.');

        return self::SUCCESS;
    }
}
