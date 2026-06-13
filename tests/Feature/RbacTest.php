<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
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

        $this->assertTrue(Role::query()->where('name', (string) config('openapi.admin_role'))->exists());

        /** @var list<string> $viewerRoles */
        $viewerRoles = (array) config('openapi.viewer_roles', []);
        foreach ($viewerRoles as $viewerRole) {
            $this->assertTrue(Role::query()->where('name', $viewerRole)->exists(), "Role '{$viewerRole}' not found.");
        }

        $admin = User::query()->where('email', config('openapi.admin_user.email'))->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole(config('openapi.admin_role')));
    }

    public function test_role_admin_middleware_forbids_non_admin_and_allows_admin(): void
    {
        $adminRole = (string) config('openapi.admin_role');

        /** @var list<string> $viewerRoles */
        $viewerRoles = (array) config('openapi.viewer_roles', []);
        // Pick a non-admin viewer role (first role that is not the admin role).
        $viewerRole = collect($viewerRoles)->first(fn (string $r) => $r !== $adminRole) ?? 'user';

        Route::middleware(['web', 'auth', "role:{$adminRole}"])->get('/__test-admin-only', fn () => 'ok');

        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create();
        $user->assignRole($viewerRole);
        $this->actingAs($user)->get('/__test-admin-only')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $this->actingAs($admin)->get('/__test-admin-only')->assertOk();
    }
}
