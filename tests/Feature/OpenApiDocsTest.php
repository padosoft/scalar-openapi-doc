<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScalarServer;
use App\Models\User;
use App\Models\UserAllowedEndpoint;
use App\Models\UserAllowedTag;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_scalar_api_proxy(): void
    {
        $response = $this->get('/api-docs/openapi.json');
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_viewer_can_fetch_filtered_proxy_spec_with_injected_servers(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $user = User::factory()->create();
        $user->assignRole($this->viewerRole());
        UserAllowedTag::create([
            'user_id' => $user->id,
            'tag' => 'Catalog',
        ]);
        UserAllowedEndpoint::create([
            'user_id' => $user->id,
            'method' => 'GET',
            'path' => '/orders/{id}',
        ]);

        $gateway = ScalarServer::create([
            'url' => 'https://scalar-proxy.local',
            'description' => 'Gateway',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        ScalarServer::create([
            'url' => 'https://inactive.local',
            'description' => 'Dormant',
            'sort_order' => 20,
            'is_active' => false,
        ]);
        // Servers are deny-by-default per user: grant the Gateway to this viewer.
        $user->allowedServers()->attach($gateway->id);

        $response = $this->actingAs($user)->get('/api-docs/openapi.json');

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertEqualsCanonicalizing(
            ['private', 'no-store'],
            array_map('trim', explode(',', $cacheControl)),
        );

        $payload = $response->json();

        $this->assertArrayHasKey('/orders/{id}', $payload['paths']);
        $this->assertArrayNotHasKey('/admin', $payload['paths']);
        $this->assertSame([
            ['url' => 'https://scalar-proxy.local', 'description' => 'Gateway'],
        ], $payload['servers']);
        $this->assertArrayNotHasKey('servers', $payload['paths']['/orders/{id}']['get']);
    }

    public function test_viewer_without_granted_servers_sees_no_servers(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $user = User::factory()->create();
        $user->assignRole($this->viewerRole());
        UserAllowedTag::create([
            'user_id' => $user->id,
            'tag' => 'Catalog',
        ]);

        // An active server exists, but it is not granted to this user.
        ScalarServer::create([
            'url' => 'https://scalar-proxy.local',
            'description' => 'Gateway',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $payload = $this->actingAs($user)->get('/api-docs/openapi.json')->json();

        $this->assertArrayHasKey('/orders/{id}', $payload['paths']);
        // Deny-by-default: no granted servers ⇒ the spec exposes no servers at all
        // (the upstream `servers` list is stripped too).
        $this->assertArrayNotHasKey('servers', $payload);
    }

    public function test_admin_sees_all_active_servers_without_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $admin = User::factory()->create();
        $admin->assignRole((string) config('openapi.admin_role', 'admin'));

        ScalarServer::create([
            'url' => 'https://scalar-proxy.local',
            'description' => 'Gateway',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        ScalarServer::create([
            'url' => 'https://inactive.local',
            'description' => 'Dormant',
            'sort_order' => 20,
            'is_active' => false,
        ]);

        $payload = $this->actingAs($admin)->get('/api-docs/openapi.json')->json();

        // Admin bypasses per-user server grants: all ACTIVE servers, inactive excluded.
        $this->assertSame([
            ['url' => 'https://scalar-proxy.local', 'description' => 'Gateway'],
        ], $payload['servers']);
    }

    public function test_admin_bypasses_filtering_and_sees_full_spec(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $adminRole = (string) config('openapi.admin_role', 'admin');
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->get('/api-docs/openapi.json');
        $response->assertOk();

        $payload = $response->json();
        $this->assertArrayHasKey('/orders/{id}', $payload['paths']);
        $this->assertArrayHasKey('/admin', $payload['paths']);
    }

    public function test_admin_can_view_openapi_metadata_endpoints_and_flush_cache(): void
    {
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $this->seed(DatabaseSeeder::class);
        $this->seedOpenApiSpec();

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $tagsResponse = $this->actingAs($admin)->get('/api-docs/meta/tags');
        $tagsResponse->assertOk();
        $tags = $tagsResponse->json();
        $this->assertSame(['Admin', 'Catalog'], $tags);

        $endpointsResponse = $this->actingAs($admin)->get('/api-docs/meta/endpoints');
        $endpointsResponse->assertOk();
        $endpoints = $endpointsResponse->json();
        $this->assertContains([
            'method' => 'GET',
            'path' => '/orders/{id}',
            'label' => 'GET /orders/{id}',
            'summary' => 'Get order',
        ], $endpoints);

        config([
            'openapi.cache_key' => 'openapi-test-cache',
            'openapi.stale_key' => 'openapi-test-cache-stale',
        ]);
        Cache::put(config('openapi.cache_key'), ['openapi' => '3.1.0', 'info' => ['title' => 'A', 'version' => '1']], 3600);
        Cache::forever(config('openapi.stale_key'), ['openapi' => '3.1.0', 'info' => ['title' => 'B', 'version' => '1']]);

        $flushResponse = $this->actingAs($admin)->delete('/api-docs/flush-cache');
        $flushResponse->assertOk();
        $flushResponse->assertJson(['status' => 'cleared']);
        $this->assertFalse(Cache::has(config('openapi.cache_key')));
        $this->assertFalse(Cache::has(config('openapi.stale_key')));
    }

    public function test_admin_only_endpoints_are_forbidden_for_non_admin_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($this->viewerRole());

        $this->actingAs($user)->get('/api-docs/meta/tags')->assertForbidden();
        $this->actingAs($user)->delete('/api-docs/flush-cache')->assertForbidden();
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

    public function test_docs_endpoint_serves_a_valid_unavailable_document_when_spec_cannot_load(): void
    {
        $this->seed(DatabaseSeeder::class);
        config([
            // Pin the spec cache to the default store the Cache facade clears below;
            // an env-set OPENAPI_CACHE_STORE would otherwise divert the service.
            'openapi.cache_store' => null,
            'openapi.cache_key' => 'docs-unavailable-raw',
            'openapi.stale_key' => 'docs-unavailable-stale',
            'openapi.upstream_url' => 'http://127.0.0.1:1/openapi.json',
            'openapi.allowed_hosts' => ['127.0.0.1'],
            'openapi.allowed_schemes' => ['http'],
        ]);
        Cache::forget('docs-unavailable-raw');
        Cache::forget('docs-unavailable-stale');
        Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response([], 500)]);

        $user = User::factory()->create();
        $user->assignRole($this->viewerRole());

        $response = $this->actingAs($user)->get('/api-docs/openapi.json');

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame('3.1.0', $payload['openapi']);
        $this->assertStringContainsString('temporarily unavailable', $payload['info']['title']);
        $this->assertStringContainsString('Upstream API error', $payload['info']['description']);
        $this->assertArrayHasKey('paths', $payload);
        $this->assertSame([], $payload['paths']);
    }

    private function seedOpenApiSpec(): void
    {
        config([
            'openapi.cache_key' => 'openapi-test-openapi-proxy',
            'openapi.stale_key' => 'openapi-test-openapi-proxy-stale',
        ]);

        Cache::put(config('openapi.cache_key'), [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Catalog Service',
                'version' => '1.0.0',
            ],
            'paths' => [
                '/orders/{id}' => [
                    'get' => [
                        'tags' => ['Catalog'],
                        'summary' => 'Get order',
                        'servers' => [
                            ['url' => 'https://orders.example.com'],
                        ],
                    ],
                ],
                '/admin' => [
                    'get' => [
                        'tags' => ['Admin'],
                        'summary' => 'Admin only',
                    ],
                ],
            ],
            'servers' => [
                ['url' => 'https://upstream.example.com'],
            ],
        ], 3600);
    }
}
