<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the application roles (admin + viewer roles) from config, so the role
 * names are never hardcoded to the literal "admin"/"user".
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Reset spatie's cached permissions before seeding.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles must use the application's default auth guard — that's the guard
        // spatie resolves from the authenticatable when checking/assigning roles,
        // so seeding under any other guard would make hasRole()/assignRole() miss.
        $guard = config('auth.defaults.guard', 'web');
        $guard = is_string($guard) ? $guard : 'web';

        foreach ($this->roleNames() as $role) {
            Role::findOrCreate($role, $guard);
        }
    }

    /**
     * Admin role + viewer roles, de-duplicated. Only non-empty string entries
     * are kept, so a misconfigured value can't create a blank role.
     *
     * @return list<string>
     */
    private function roleNames(): array
    {
        $viewers = config('openapi.viewer_roles', []);
        $admin = config('openapi.admin_role');

        $names = [
            ...(is_string($admin) ? [$admin] : []),
            ...(is_array($viewers) ? $viewers : []),
        ];

        $names = array_values(array_filter(
            $names,
            static fn (mixed $n): bool => is_string($n) && $n !== '',
        ));

        /** @var list<string> $names */
        return array_values(array_unique($names));
    }
}
