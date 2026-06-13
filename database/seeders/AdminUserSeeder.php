<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Idempotently creates the initial administrator and assigns the admin role.
 *
 * Credentials come from config('openapi.admin_user.*') (backed by ADMIN_EMAIL /
 * ADMIN_PASSWORD / ADMIN_NAME), read via config rather than env() directly so it
 * keeps working when the config cache is warm.
 */
final class AdminUserSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'change-me';

    public function run(): void
    {
        $email = $this->stringConfig('openapi.admin_user.email');
        // Whitespace can't smuggle a default/empty password past the guard:
        // stringConfig() trims, so " change-me " becomes "change-me".
        $password = $this->stringConfig('openapi.admin_user.password', allowEmpty: true);
        $name = $this->stringConfig('openapi.admin_user.name');
        $adminRole = $this->stringConfig('openapi.admin_role');

        // Never provision a predictable admin outside local/testing: refuse to
        // seed when the password is empty or still the documented placeholder.
        // This fails closed in production AND staging (any non-local/test env).
        $isTrustedEnv = app()->environment(['local', 'testing']);
        if (! $isTrustedEnv && ($password === '' || $password === self::DEFAULT_PASSWORD)) {
            throw new RuntimeException(
                'Refusing to seed the admin user outside local/testing with an empty or default password. Set ADMIN_PASSWORD.'
            );
        }

        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if (! $admin->hasRole($adminRole)) {
            $admin->assignRole($adminRole);
        }
    }

    /**
     * Read a config value, asserting it is a string, then trim it. Unless
     * $allowEmpty is set, a value that is empty (or whitespace-only) after
     * trimming fails fast instead of producing an invalid record.
     */
    private function stringConfig(string $key, bool $allowEmpty = false): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new RuntimeException("Config [{$key}] must be a string.");
        }

        $value = trim($value);

        if (! $allowEmpty && $value === '') {
            throw new RuntimeException("Config [{$key}] must be a non-empty string.");
        }

        return $value;
    }
}
