<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
