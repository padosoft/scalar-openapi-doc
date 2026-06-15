<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScalarServer;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_mutations_reject_invalid_csrf_tokens(): void
    {
        $this->markTestSkipped('CSRF middleware is bypassed in Laravel feature tests (`PreventRequestForgery::handle` returns early when runningUnitTests()). Validate CSRF rejection in Playwright E2E coverage.');

        $admin = $this->createAdmin();
        $viewer = User::factory()->create();
        $viewer->assignRole($this->viewerRole());
        $server = ScalarServer::query()->create([
            'url' => 'https://example.local/api',
            'description' => 'Editable server',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $invalidHeaders = ['X-CSRF-TOKEN' => 'invalid', 'Accept' => 'application/json'];

        $userCountBefore = User::query()->count();
        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->post('/admin/users', [
                'name' => 'Bad csrf',
                'email' => 'bad-csrf@example.com',
                'password' => 'password',
                'role' => $this->viewerRole(),
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
            ]);
        $this->assertCsrfBlocked($response);
        $this->assertSame($userCountBefore, User::query()->count());

        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->put('/admin/users/'.$viewer->id, [
                'name' => $viewer->name,
                'email' => $viewer->email,
                'password' => 'password',
                'role' => $this->viewerRole(),
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
            ]);
        $this->assertCsrfBlocked($response);
        $this->assertSame($userCountBefore, User::query()->count());
        $this->assertNotNull(User::query()->find($viewer->id));

        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->delete('/admin/users/'.$viewer->id);
        $this->assertCsrfBlocked($response);
        $this->assertNotNull(User::query()->find($viewer->id));

        $serversBefore = ScalarServer::query()->count();
        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->post('/servers', [
                'url' => 'https://forbidden.invalid',
                'description' => 'Forbidden server',
                'sort_order' => 1,
                'is_active' => true,
            ]);
        $this->assertCsrfBlocked($response);
        $this->assertSame($serversBefore, ScalarServer::query()->count());

        $serverSortOrderBefore = ScalarServer::query()->findOrFail($server->id)->sort_order;
        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->put('/servers/'.$server->id, [
                'url' => $server->url,
                'description' => 'Forbidden update',
                'sort_order' => 20,
                'is_active' => false,
            ]);
        $this->assertCsrfBlocked($response);
        $this->assertSame($serverSortOrderBefore, ScalarServer::query()->findOrFail($server->id)->sort_order);

        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->delete('/servers/'.$server->id);
        $this->assertCsrfBlocked($response);
        $this->assertNotNull(ScalarServer::query()->find($server->id));

        $openApiCacheKey = (string) config('openapi.cache_key', 'openapi:spec:raw');
        Cache::put($openApiCacheKey, ['cached' => true], 3600);
        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->delete('/api-docs/flush-cache');
        $this->assertCsrfBlocked($response);
        $this->assertEquals(['cached' => true], Cache::get($openApiCacheKey));

        $response = $this->actingAs($admin)
            ->withHeaders($invalidHeaders)
            ->post('/api-docs/flush-cache');
        $this->assertCsrfBlocked($response);
        $this->assertEquals(['cached' => true], Cache::get($openApiCacheKey));

    }

    public function test_user_mutation_endpoints_ignore_unexpected_mass_assignment_keys(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Mass assigned user',
                'email' => 'mass-assignment@example.com',
                'password' => 'Password123!',
                'role' => $this->viewerRole(),
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
                'id' => 999999,
                'email_verified_at' => '2026-06-15 00:00:00',
            ]);

        $response->assertRedirect('/admin/users');

        $user = User::query()->where('email', 'mass-assignment@example.com')->firstOrFail();
        $this->assertNotSame(999999, $user->id);
        $this->assertNull($user->email_verified_at);

        $originalHash = $user->password;
        $this->actingAs($admin)
            ->from('/admin/users/'.$user->id.'/edit')
            ->put('/admin/users/'.$user->id, [
                'name' => $user->name,
                'email' => $user->email,
                'password' => '',
                'role' => $this->viewerRole(),
                'grants' => [
                    'tags' => [],
                    'endpoints' => [],
                ],
                'id' => 111111,
                'email_verified_at' => '2026-12-31 00:00:00',
            ]);

        $user->refresh();
        $this->assertNotSame(111111, $user->id);
        $this->assertSame($originalHash, $user->password);
        $this->assertNull($user->email_verified_at);
    }

    public function test_scalar_server_endpoints_ignore_unexpected_mass_assignment_keys(): void
    {
        $admin = $this->createAdmin();

        $initialCreatedAt = Carbon::parse('2026-06-01 10:00:00');
        $server = new ScalarServer([
            'url' => 'https://stable.local/api',
            'description' => 'Baseline server',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $server->created_at = $initialCreatedAt;
        $server->save();

        $payload = [
            'url' => 'https://stable.local/api',
            'description' => 'Patched server',
            'sort_order' => 2,
            'is_active' => false,
            'id' => 888888,
            'created_at' => '2000-01-01 00:00:00',
        ];

        $createResponse = $this->actingAs($admin)
            ->post('/servers', [
                ...$payload,
                'url' => 'https://created-by-test.local/api',
                'email_verified_at' => '2020-01-01 00:00:00',
            ]);
        $createResponse->assertRedirect('/servers');

        $createdServer = ScalarServer::query()->where('url', 'https://created-by-test.local/api')->first();
        $this->assertNotNull($createdServer);
        $this->assertNotSame(888888, $createdServer->id);

        $response = $this->actingAs($admin)
            ->put('/servers/'.$server->id, $payload);
        $response->assertRedirect('/servers');

        $server->refresh();
        $this->assertNotSame(888888, $server->id);
        $this->assertSame(2, $server->sort_order);
        $this->assertNotSame($payload['created_at'], $server->created_at->toDateTimeString());
        $this->assertSame($initialCreatedAt->toDateTimeString(), $server->created_at->toDateTimeString());
    }

    private function createAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $user = User::factory()->create();
        $user->assignRole($adminRole);
        $this->seedOpenApiSpecCacheForValidation();

        return $user;
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

        $fallbackRole = 'user';
        Role::findOrCreate($fallbackRole, (string) config('auth.defaults.guard', 'web'));

        return $fallbackRole;
    }

    private function seedOpenApiSpecCacheForValidation(): void
    {
        $cacheKey = (string) config('openapi.cache_key', 'openapi:spec:raw');
        $staleKey = (string) config('openapi.stale_key', 'openapi:spec:stale');

        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Hardening Catalog', 'version' => '1'],
            'paths' => [
                '/openapi-cache' => [
                    'get' => [
                        'summary' => 'Probe endpoint',
                        'tags' => ['Catalog'],
                    ],
                    'delete' => [
                        'summary' => 'Admin route',
                        'tags' => ['Catalog'],
                    ],
                ],
            ],
            'components' => ['securitySchemes' => []],
            'webhooks' => [],
        ];

        Cache::put($cacheKey, $spec, 3600);
        Cache::put($staleKey, $spec, 3600);
    }
}
