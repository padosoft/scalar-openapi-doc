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

        foreach ($this->roleNames() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * Admin role + viewer roles, de-duplicated.
     *
     * @return list<string>
     */
    private function roleNames(): array
    {
        /** @var list<string> $viewers */
        $viewers = config('openapi.viewer_roles', []);
        $admin = (string) config('openapi.admin_role');

        return array_values(array_unique([$admin, ...$viewers]));
    }
}
