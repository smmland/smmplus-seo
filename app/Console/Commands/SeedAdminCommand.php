<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedAdminCommand extends Command
{
    protected $signature = 'admin:seed';

    protected $description = 'Create or update the panel admin user from ADMIN_EMAIL / ADMIN_PASSWORD env vars';

    public function handle(): int
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in the environment before seeding.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password)],
        );

        $this->info("Admin user ready: {$email}");

        return self::SUCCESS;
    }
}
