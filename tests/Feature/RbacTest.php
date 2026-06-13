<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_roles_and_assigns_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Roles are scoped by (name, guard_name); assert against the app's guard.
        $guard = (string) config('auth.defaults.guard', 'web');

        $this->assertTrue(Role::query()
            ->where('name', (string) config('openapi.admin_role'))
            ->where('guard_name', $guard)
            ->exists());

        /** @var list<string> $viewerRoles */
        $viewerRoles = (array) config('openapi.viewer_roles', []);
        foreach ($viewerRoles as $viewerRole) {
            $this->assertTrue(
                Role::query()->where('name', $viewerRole)->where('guard_name', $guard)->exists(),
                "Role '{$viewerRole}' not found for guard '{$guard}'.",
            );
        }

        $admin = User::query()->where('email', config('openapi.admin_user.email'))->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole(config('openapi.admin_role')));
    }

    public function test_admin_seeder_refuses_default_password_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['openapi.admin_user.password' => 'change-me']);

        $this->expectException(\RuntimeException::class);

        // Invoke the seeder directly to test its guard (bypasses db:seed's own
        // production confirmation prompt).
        app(AdminUserSeeder::class)->run();
    }

    public function test_role_admin_middleware_forbids_non_admin_and_allows_admin(): void
    {
        $adminRole = (string) config('openapi.admin_role');

        Route::middleware(['web', 'auth', "role:{$adminRole}"])->get('/__test-admin-only', fn () => 'ok');

        $this->seed(DatabaseSeeder::class);

        // A non-admin user is forbidden. Create a dedicated non-admin role here so
        // the test holds even under an "admins only" viewer_roles configuration.
        $nonAdminRole = Role::findOrCreate('rbac-test-non-admin', (string) config('auth.defaults.guard', 'web'));
        $user = User::factory()->create();
        $user->assignRole($nonAdminRole);
        $this->actingAs($user)->get('/__test-admin-only')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $this->actingAs($admin)->get('/__test-admin-only')->assertOk();
    }
}
