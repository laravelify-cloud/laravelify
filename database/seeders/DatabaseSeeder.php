<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Server services are inserted by the migration.
     * This creates the one superadmin so /setup is skipped.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password123',
                'two_factor_secret' => 'UCWLL2EAY2YZE5XNHRYPKTOS7BV7KHSI',
                'two_factor_confirmed_at' => now(),
                'email_verified_at' => now(),
            ],
        );
    }
}
