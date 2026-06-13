<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotently creates the initial administrator and assigns the admin role.
 *
 * Credentials come from config('openapi.admin_user.*') (backed by ADMIN_EMAIL /
 * ADMIN_PASSWORD / ADMIN_NAME), read via config rather than env() directly so it
 * keeps working when the config cache is warm.
 */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('openapi.admin_user.email');

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('openapi.admin_user.name'),
                'password' => Hash::make((string) config('openapi.admin_user.password')),
                'email_verified_at' => now(),
            ],
        );

        $adminRole = (string) config('openapi.admin_role');
        if (! $admin->hasRole($adminRole)) {
            $admin->assignRole($adminRole);
        }
    }
}
