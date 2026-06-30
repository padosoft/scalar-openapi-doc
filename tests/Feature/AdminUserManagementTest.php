<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HttpVerb;
use App\Models\ScalarServer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_index_is_available_only_to_admins(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->createAdmin();
        $viewerRole = Role::findOrCreate('viewer', 'web');
        $viewer = User::factory()->create();
        $viewer->assignRole($viewerRole);

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($viewer)->get('/admin/users')->assertForbidden();
    }

    public function test_admin_users_index_serializes_granted_server_count(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->createAdmin();

        $serverIds = [
            ScalarServer::create(['url' => 'https://one.local', 'description' => 'One', 'sort_order' => 1, 'is_active' => true])->id,
            ScalarServer::create(['url' => 'https://two.local', 'description' => 'Two', 'sort_order' => 2, 'is_active' => true])->id,
        ];

        $grantee = User::factory()->create();
        $grantee->assignRole(Role::findOrCreate('user', 'web'));
        $grantee->allowedServers()->sync($serverIds);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->where(
                    'users',
                    fn (Collection $users): bool => $users
                        ->firstWhere('id', $grantee->id)['grants']['servers'] === 2
                )
            );
    }

    public function test_admin_can_create_users_with_valid_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'External Readonly',
            'email' => 'viewer@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => ['Catalog'],
                'endpoints' => ['GET /orders/{id}'],
            ],
        ]);

        $response->assertRedirect('/admin/users');
        $created = User::query()->where('email', 'viewer@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('user'));
        $this->assertSame(['Catalog'], $created->allowedTags->pluck('tag')->all());
        $this->assertSame(['GET /orders/{id}'], $created->allowedEndpoints->map(
            fn ($endpoint): string => $endpoint->method->value.' '.$endpoint->path
        )->all());
    }

    public function test_admin_can_create_user_with_server_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();
        $server = ScalarServer::create([
            'url' => 'https://grantable.local',
            'description' => 'Grantable',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Server Grantee',
            'email' => 'server-grantee@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => [],
                'endpoints' => [],
                'servers' => [$server->id],
            ],
        ]);

        $response->assertRedirect('/admin/users');
        $created = User::query()->where('email', 'server-grantee@example.com')->firstOrFail();
        $this->assertSame([$server->id], $created->allowedServers->modelKeys());
    }

    public function test_admin_can_keep_existing_grant_to_a_deactivated_server(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();

        $server = ScalarServer::create([
            'url' => 'https://was-active.local',
            'description' => 'Was active',
            'sort_order' => 1,
            'is_active' => false,
        ]);
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->allowedServers()->attach($server->id);

        // The server is now inactive but already assigned, so re-submitting it
        // from the edit form must be accepted (not treated as tampering).
        $response = $this->actingAs($admin)->from('/admin/users/'.$user->id.'/edit')->put(
            '/admin/users/'.$user->id,
            [
                'name' => $user->name,
                'email' => $user->email,
                'password' => '',
                'role' => 'user',
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                    'servers' => [$server->id],
                ],
            ],
        );

        $response->assertRedirect('/admin/users');
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame([$server->id], $user->fresh()->allowedServers->modelKeys());
    }

    public function test_admin_user_request_rejects_unknown_server_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Bad Server User',
            'email' => 'bad-server@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => [],
                'endpoints' => [],
                'servers' => [999999],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['grants.servers.0']);
        $this->assertDatabaseMissing('users', ['email' => 'bad-server@example.com']);
    }

    public function test_admin_user_request_rejects_inactive_unassigned_server_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();
        // Exists but is inactive and not already assigned to anyone — a tampered
        // request must not be able to grant it (it is never offered in the UI).
        $inactive = ScalarServer::create([
            'url' => 'https://inactive.local',
            'description' => 'Inactive',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Inactive Server User',
            'email' => 'inactive-server@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => [],
                'endpoints' => [],
                'servers' => [$inactive->id],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['grants.servers.0']);
        $this->assertDatabaseMissing('users', ['email' => 'inactive-server@example.com']);
    }

    public function test_admin_user_request_rejects_unknown_tag_or_endpoint_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Bad User',
            'email' => 'bad@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => ['NotARealTag'],
                'endpoints' => ['GET /orders/{id}'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors([
            'grants.tags.0',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'bad@example.com']);
    }

    public function test_admin_user_request_rejects_unknown_endpoint_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Bad Endpoint User',
            'email' => 'bad-endpoint@example.com',
            'password' => 'password',
            'role' => 'user',
            'grants' => [
                'tags' => ['Catalog'],
                'endpoints' => ['GET /orders/{id}', 'DELETE /forbidden', 'not-a-grant'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors([
            'grants.endpoints.1',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'bad-endpoint@example.com']);
    }

    public function test_admin_can_update_user_with_existing_grants_when_openapi_catalog_is_unavailable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->createAdmin();

        $cacheKey = 'admin-users-openapi-availability-test';
        $cacheStale = "{$cacheKey}-stale";
        config([
            'openapi.cache_key' => $cacheKey,
            'openapi.stale_key' => $cacheStale,
            'openapi.upstream_url' => 'http://127.0.0.1:1/openapi.json',
            'openapi.allowed_hosts' => ['127.0.0.1'],
            'openapi.allowed_schemes' => ['http'],
        ]);
        Cache::forget($cacheKey);
        Cache::forget($cacheStale);

        Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response([], 500)]);

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->allowedTags()->create(['tag' => 'Catalog']);
        $user->allowedEndpoints()->create([
            'method' => HttpVerb::Get,
            'path' => '/orders/{id}',
        ]);

        $response = $this->actingAs($admin)->from('/admin/users/'.$user->id.'/edit')->put(
            '/admin/users/'.$user->id,
            [
                'name' => 'Updated user',
                'email' => $user->email,
                'password' => '',
                'role' => 'user',
                'grants' => [
                    'tags' => ['Catalog'],
                    'endpoints' => ['GET /orders/{id}'],
                ],
            ],
        );

        $response->assertRedirect('/admin/users');
        $response->assertSessionDoesntHaveErrors();

        $user->refresh();
        $this->assertSame('Updated user', $user->name);
        $this->assertSame(['Catalog'], $user->allowedTags->pluck('tag')->all());
        $this->assertSame(['GET /orders/{id}'], $user->allowedEndpoints->map(
            fn ($endpoint): string => $endpoint->method->value.' '.$endpoint->path
        )->all());
    }

    public function test_edit_form_reports_openapi_unavailable_and_still_renders(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->createAdmin();

        $cacheKey = 'admin-users-openapi-edit-unavailable';
        config([
            'openapi.cache_key' => $cacheKey,
            'openapi.stale_key' => "{$cacheKey}-stale",
            'openapi.upstream_url' => 'http://127.0.0.1:1/openapi.json',
            'openapi.allowed_hosts' => ['127.0.0.1'],
            'openapi.allowed_schemes' => ['http'],
        ]);
        Cache::forget($cacheKey);
        Cache::forget("{$cacheKey}-stale");
        Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response([], 500)]);

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->allowedTags()->create(['tag' => 'Catalog']);

        $response = $this->actingAs($admin)->get('/admin/users/'.$user->id.'/edit');

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('admin/users/form')
                ->where('openapiStatus.ok', false)
                ->where('openapiStatus.failure.category', 'external_api')
                // Degraded mode still offers the user's existing grants as options.
                ->where('openapi.tags', [['value' => 'Catalog', 'label' => 'Catalog']])
        );
    }

    public function test_create_form_reports_openapi_available_when_catalog_loads(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->createAdmin();
        $this->seedOpenApiSpec();

        $response = $this->actingAs($admin)->get('/admin/users/create');

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('admin/users/form')
                ->where('openapiStatus.ok', true)
        );
    }

    public function test_admin_cannot_delete_the_last_administrator(): void
    {
        $admin = $this->createAdmin();
        $this->dropOtherAdmins($admin);

        $response = $this->actingAs($admin)->from('/admin/users')->delete('/admin/users/'.$admin->id);

        $response->assertRedirect('/admin/users');
        $response->assertSessionHasErrors('user');
        $this->assertNotNull(User::query()->find($admin->id));
    }

    public function test_admin_can_demote_admin_if_another_admin_exists(): void
    {
        $admin = $this->createAdmin();
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $replacementAdmin = User::factory()->create(['email' => 'second.admin@example.com']);
        $replacementAdmin->assignRole($adminRole);

        $response = $this->actingAs($admin)->from('/admin/users/'.$replacementAdmin->id.'/edit')->put(
            '/admin/users/'.$admin->id,
            [
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'role' => 'user',
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
            ],
        );

        $response->assertRedirect('/admin/users');
        $admin->refresh();
        $this->assertTrue($admin->hasRole('user'));
    }

    public function test_admin_cannot_demote_last_admin_user(): void
    {
        $admin = $this->createAdmin();
        $this->dropOtherAdmins($admin);

        $response = $this->actingAs($admin)->from('/admin/users/'.$admin->id.'/edit')->put(
            '/admin/users/'.$admin->id,
            [
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'role' => 'user',
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
            ],
        );

        $response->assertRedirect('/admin/users/'.$admin->id.'/edit');
        $response->assertSessionHasErrors('role');
        $admin->refresh();
        $this->assertTrue($admin->hasRole((string) config('openapi.admin_role', 'admin')));
    }

    private function createAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $user = User::factory()->create();
        $user->assignRole($adminRole);

        return $user;
    }

    private function dropOtherAdmins(User $admin): void
    {
        $adminRole = $this->adminRole();
        User::role($adminRole)
            ->where('id', '!=', $admin->id)
            ->delete();
    }

    private function adminRole(): string
    {
        return (string) config('openapi.admin_role', 'admin');
    }

    private function seedOpenApiSpec(): void
    {
        $cacheKey = 'openapi-admin-users-spec';
        $cacheStale = "{$cacheKey}-stale";
        config(['openapi.cache_key' => $cacheKey, 'openapi.stale_key' => $cacheStale]);

        Cache::put($cacheKey, [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Catalog', 'version' => '1'],
            'paths' => [
                '/orders/{id}' => [
                    'get' => [
                        'tags' => ['Catalog'],
                        'summary' => 'Get order',
                    ],
                ],
                '/admin-only' => [
                    'delete' => [
                        'tags' => ['Admin'],
                        'summary' => 'Administrative delete',
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [],
            ],
            'webhooks' => [],
        ], 3600);
    }
}
