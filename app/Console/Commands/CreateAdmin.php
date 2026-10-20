<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email? : Administrator email address} {--name=Administrator : Display name}';

    protected $description = 'Create the first owner administrator without demo data';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));
        $name = trim((string) $this->option('name'));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(compact('email', 'name', 'password', 'confirmation'), [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'same:confirmation'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
            'is_owner' => true,
            'admin_role' => User::ROLE_SUPER,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
