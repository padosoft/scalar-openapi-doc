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

        $this->assertTrue(Role::query()->where('name', 'admin')->exists());
        $this->assertTrue(Role::query()->where('name', 'user')->exists());

        $admin = User::query()->where('email', config('openapi.admin_user.email'))->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole(config('openapi.admin_role')));
    }

    public function test_role_admin_middleware_forbids_non_admin_and_allows_admin(): void
    {
        Route::middleware(['web', 'auth', 'role:admin'])->get('/__test-admin-only', fn () => 'ok');

        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user)->get('/__test-admin-only')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/__test-admin-only')->assertOk();
    }
}
