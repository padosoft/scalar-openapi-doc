<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScalarServer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ScalarServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_scalar_servers(): void
    {
        $admin = $this->createAdmin();

        $create = $this->actingAs($admin)->post('/servers', [
            'url' => 'https://example.local/api',
            'description' => 'Primary',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $create->assertRedirect('/servers');
        $server = ScalarServer::query()->where('url', 'https://example.local/api')->first();
        $this->assertNotNull($server);

        $update = $this->actingAs($admin)->put("/servers/{$server->id}", [
            'url' => $server->url,
            'description' => 'Primary (updated)',
            'sort_order' => 20,
            'is_active' => false,
        ]);

        $update->assertRedirect('/servers');
        $server->refresh();
        $this->assertSame('Primary (updated)', $server->description);
        $this->assertSame(20, $server->sort_order);
        $this->assertFalse($server->is_active);

        $delete = $this->actingAs($admin)->delete("/servers/{$server->id}");
        $delete->assertRedirect();
        $this->assertDatabaseMissing('scalar_servers', ['id' => $server->id]);
    }

    public function test_scalar_servers_are_injected_only_when_active(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $user = User::factory()->create();
        $viewerRole = $this->viewerRole();
        $user->assignRole($viewerRole);

        ScalarServer::query()->create([
            'url' => 'https://active.local',
            'description' => 'Active',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        ScalarServer::query()->create([
            'url' => 'https://inactive.local',
            'description' => 'Inactive',
            'sort_order' => 2,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get('/api-docs/openapi.json');
        $response->assertOk();
        $payload = $response->json();

        $this->assertSame([
            ['url' => 'https://active.local', 'description' => 'Active'],
        ], $payload['servers']);
    }

    public function test_non_admin_users_cannot_manage_servers(): void
    {
        $user = User::factory()->create();
        $viewerRole = $this->viewerRole();
        $user->assignRole($viewerRole);

        $this->actingAs($user)
            ->post('/servers', [
                'url' => 'https://forbidden.local',
                'sort_order' => 0,
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    private function createAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        return $admin;
    }

    private function viewerRole(): string
    {
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $viewerRoles = array_values(array_filter(
            (array) config('openapi.viewer_roles', []),
            static fn (mixed $role): bool => is_string($role) && trim($role) !== '' && trim($role) !== $adminRole,
        ));

        if ($viewerRoles !== []) {
            $viewerRole = (string) $viewerRoles[0];
            Role::findOrCreate($viewerRole, (string) config('auth.defaults.guard', 'web'));

            return $viewerRole;
        }

        $fallbackRole = 'viewer';
        Role::findOrCreate($fallbackRole, (string) config('auth.defaults.guard', 'web'));
        config(['openapi.viewer_roles' => [...(array) config('openapi.viewer_roles', []), $fallbackRole]]);

        return $fallbackRole;
    }

    private function seedOpenApiSpec(): void
    {
        $cacheKey = 'openapi-server-test';
        config([
            'openapi.cache_key' => $cacheKey,
            'openapi.stale_key' => "{$cacheKey}:stale",
        ]);

        Cache::put($cacheKey, [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Catalog', 'version' => '1'],
            'paths' => [
                '/orders/{id}' => [
                    'get' => ['tags' => ['Catalog'], 'summary' => 'Get order'],
                ],
            ],
            'webhooks' => [],
        ], 3600);
        Cache::put("{$cacheKey}:stale", [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Catalog', 'version' => '1'],
            'paths' => [
                '/orders/{id}' => [
                    'get' => ['tags' => ['Catalog'], 'summary' => 'Get order'],
                ],
            ],
        ], 3600);
    }
}
