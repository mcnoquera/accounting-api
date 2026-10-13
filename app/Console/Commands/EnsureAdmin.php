<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Creates the first admin from ADMIN_EMAIL / ADMIN_PASSWORD so a fresh production
 * database can be signed into without shell access. Safe to run on every boot.
 */
class EnsureAdmin extends Command
{
    protected $signature = 'app:ensure-admin';

    protected $description = 'Create the admin user from ADMIN_EMAIL and ADMIN_PASSWORD if it does not exist';

    public function handle(): int
    {
        $email = config('ledgerly.admin.email');
        $password = config('ledgerly.admin.password');

        if (! $email || ! $password) {
            $this->line('ADMIN_EMAIL / ADMIN_PASSWORD not set; skipping admin bootstrap.');

            return self::SUCCESS;
        }

        if (strlen($password) < 12) {
            $this->error('ADMIN_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->line("Admin {$email} already exists.");

            return self::SUCCESS;
        }

        User::create([
            'name' => config('ledgerly.admin.name'),
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
        ]);
        $this->info("Created admin {$email}.");

        return self::SUCCESS;
    }
}
