<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidOpenApiSpecException;
use App\Services\OpenApiSpecService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Hardening coverage for OpenApiSpecService: SSRF guard (B4), anti
 * cache-poisoning (B3), webhooks + securityScheme-name pruning (B5),
 * injectServers validation (B7) and stale-on-error / cache-hit (B8).
 */
class OpenApiSpecHardeningTest extends TestCase
{
    private function service(): OpenApiSpecService
    {
        return app(OpenApiSpecService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec31(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/openapi31.json')), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function validSpec(): string
    {
        return (string) json_encode(['openapi' => '3.1.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => []]);
    }

    // ---- B4: SSRF guard ----------------------------------------------------

    public function test_rejects_disallowed_scheme_without_any_http_call(): void
    {
        Http::fake();
        config(['openapi.upstream_url' => 'file:///etc/passwd', 'openapi.allowed_schemes' => ['https']]);
        Cache::flush();

        $this->expectException(InvalidOpenApiSpecException::class);

        try {
            $this->service()->fetchRaw();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rejects_host_not_in_allow_list(): void
    {
        Http::fake();
        config([
            'openapi.upstream_url' => 'https://169.254.169.254/latest/meta-data',
            'openapi.allowed_schemes' => ['https'],
            'openapi.allowed_hosts' => ['specs.example.com'],
        ]);
        Cache::flush();

        $this->expectException(InvalidOpenApiSpecException::class);

        try {
            $this->service()->fetchRaw();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_blank_allowed_hosts_locks_to_the_configured_upstream_host(): void
    {
        // With OPENAPI_ALLOWED_HOSTS blank (the .env.example default), host
        // validation is NOT disabled: it falls back to the upstream URL's own
        // host, so a fetch of the configured upstream still succeeds.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json', 'openapi.allowed_hosts' => []]);
        Cache::flush();
        Http::fake(['*' => Http::response($this->validSpec(), 200, ['Content-Type' => 'application/json'])]);

        $this->service()->fetchRaw();

        expect(Cache::has((string) config('openapi.cache_key')))->toBeTrue();
    }

    public function test_redacts_upstream_url_secrets_when_logging_failures(): void
    {
        // Userinfo (incl. a password containing '@') + signed query in the
        // upstream URL must never reach the logs — not in the url context, nor
        // via the HTTP client's exception message.
        config([
            'openapi.upstream_url' => 'https://user:p@ss@specs.example.com/openapi.json?token=abc123',
            'openapi.allowed_hosts' => ['specs.example.com'],
        ]);
        Cache::flush();
        Http::fake(function (): void {
            throw new ConnectionException(
                'cURL error 7: Failed to connect to https://user:p@ss@specs.example.com/openapi.json?token=abc123'
            );
        });
        Log::spy();

        try {
            $this->service()->fetchRaw();
        } catch (\Throwable) {
            // no stale copy -> rethrows; not under test here
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            $blob = $message.' '.(string) json_encode($context);

            return ! str_contains($blob, 'abc123')      // signed query token
                && ! str_contains($blob, 'p@ss')        // password (with '@')
                && ! str_contains($blob, 'user:')       // userinfo
                && is_string($context['url'] ?? null)
                && str_contains($context['url'], 'specs.example.com');
        })->once();
    }

    // ---- B3: anti cache-poisoning -----------------------------------------

    public function test_does_not_cache_a_non_openapi_payload(): void
    {
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json', 'openapi.allowed_hosts' => ['specs.example.com']]);
        Cache::flush();
        Http::fake(['*' => Http::response('<html>not json</html>', 200, ['Content-Type' => 'text/html'])]);

        try {
            $this->service()->fetchRaw();
        } catch (\Throwable) {
            // expected: no stale copy -> rethrows
        }

        expect(Cache::has((string) config('openapi.cache_key')))->toBeFalse();
    }

    public function test_serves_stale_copy_when_upstream_returns_garbage(): void
    {
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json', 'openapi.allowed_hosts' => ['specs.example.com']]);
        Cache::flush();
        $stale = ['openapi' => '3.1.0', 'info' => ['title' => 'stale', 'version' => '1'], 'paths' => []];
        Cache::forever((string) config('openapi.stale_key'), $stale);
        Http::fake(['*' => Http::response('nonsense', 200)]);

        expect($this->service()->fetchRaw())->toBe($stale);
    }

    // ---- B8: stale-on-error + cache hit -----------------------------------

    public function test_serves_stale_copy_on_upstream_failure(): void
    {
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json', 'openapi.allowed_hosts' => ['specs.example.com']]);
        Cache::flush();
        $stale = ['openapi' => '3.1.0', 'info' => ['title' => 'stale', 'version' => '1'], 'paths' => []];
        Cache::forever((string) config('openapi.stale_key'), $stale);
        Http::fake(['*' => Http::response('error', 500)]);

        expect($this->service()->fetchRaw())->toBe($stale);
    }

    public function test_cache_hit_short_circuits_http(): void
    {
        Http::fake();
        $cached = ['openapi' => '3.1.0', 'info' => ['title' => 'cached', 'version' => '1'], 'paths' => []];
        Cache::put((string) config('openapi.cache_key'), $cached, 3600);

        expect($this->service()->fetchRaw())->toBe($cached);
        Http::assertNothingSent();
    }

    public function test_caches_a_valid_spec(): void
    {
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json', 'openapi.allowed_hosts' => ['specs.example.com']]);
        Cache::flush();
        Http::fake(['*' => Http::response($this->validSpec(), 200, ['Content-Type' => 'application/json'])]);

        $this->service()->fetchRaw();

        expect(Cache::has((string) config('openapi.cache_key')))->toBeTrue();
    }

    // ---- B5: webhooks + securitySchemes pruning ---------------------------

    public function test_filters_webhooks_and_preserves_used_security_scheme_by_name(): void
    {
        // Grant only the Orders tag (non-admin): /secure survives, the Webhooks
        // webhook is dropped, the used ApiKeyAuth scheme is kept, UnusedAuth pruned.
        $filtered = $this->service()->filterForUser($this->spec31(), collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/secure'])
            ->and($filtered)->not->toHaveKey('webhooks')
            ->and(array_keys($filtered['components']['securitySchemes']))->toBe(['ApiKeyAuth'])
            ->and(array_keys($filtered['components']['schemas']))->toBe(['OrderEvent'])
            // /secure declares its own security, so no survivor inherits root:
            // the (redundant) root requirement is dropped, the scheme stays.
            ->and($filtered)->not->toHaveKey('security');
    }

    public function test_keeps_only_granted_webhook_and_drops_secured_path(): void
    {
        $filtered = $this->service()->filterForUser($this->spec31(), collect(['Webhooks']), collect([]));

        expect($filtered['paths'])->toBe([])
            ->and(array_keys($filtered['webhooks']))->toBe(['newOrder'])
            ->and(array_keys($filtered['components']['schemas']))->toBe(['WebhookPayload'])
            // newOrder has no own security, so it inherits the root requirement,
            // which is therefore kept (and its ApiKeyAuth scheme survives pruning).
            ->and($filtered)->toHaveKey('security')
            ->and(array_keys($filtered['components']['securitySchemes']))->toBe(['ApiKeyAuth']);
    }

    public function test_non_admin_without_grants_receives_no_webhooks(): void
    {
        $filtered = $this->service()->filterForUser($this->spec31(), collect([]), collect([]));

        expect($filtered['paths'])->toBe([])
            ->and($filtered)->not->toHaveKey('webhooks');
    }

    public function test_root_security_schemes_are_dropped_when_no_operation_survives(): void
    {
        // The 3.1 fixture declares a root-level `security: [{ApiKeyAuth: []}]`. A
        // non-admin with no grants keeps zero operations, so no operation inherits
        // the root security and the securityScheme must NOT survive pruning.
        $filtered = $this->service()->filterForUser($this->spec31(), collect([]), collect([]));

        // Both the scheme AND the now-dangling root `security` requirement go.
        expect($filtered)->not->toHaveKey('components')
            ->and($filtered)->not->toHaveKey('security');
    }

    // ---- metadata includes webhooks (grant UI consistency) ----------------

    public function test_extract_tags_includes_webhook_tags(): void
    {
        // The 3.1 fixture's "Webhooks" tag exists only on a webhook operation.
        expect($this->service()->extractTags($this->spec31()))->toContain('Webhooks')->toContain('Orders');
    }

    public function test_extract_endpoints_includes_webhook_operations(): void
    {
        $endpoints = collect($this->service()->extractEndpoints($this->spec31()));

        // Webhook operations carry a "(webhook)" label and a namespaced grant path.
        expect($endpoints->pluck('label')->all())
            ->toContain('GET /secure')->toContain('POST newOrder (webhook)')
            ->and($endpoints->firstWhere('label', 'POST newOrder (webhook)')['path'])
            ->toBe('webhook:newOrder');
    }

    public function test_path_and_webhook_endpoint_grants_do_not_collide(): void
    {
        // A webhook keyed identically to a real path (both "/orders") must not be
        // exposed by a grant for the other container.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/orders' => ['post' => ['responses' => ['200' => ['description' => 'ok']]]]],
            'webhooks' => ['/orders' => ['post' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ];

        $byPath = $this->service()->filterForUser($spec, collect([]), collect(['POST /orders']));
        expect(array_keys($byPath['paths']))->toBe(['/orders'])
            ->and($byPath)->not->toHaveKey('webhooks');

        $byWebhook = $this->service()->filterForUser($spec, collect([]), collect(['POST webhook:/orders']));
        expect($byWebhook['paths'])->toBe([])
            ->and(array_keys($byWebhook['webhooks']))->toBe(['/orders']);
    }

    // ---- B4: path-item $ref reuse (OpenAPI 3.1 components.pathItems) -------

    /**
     * @return array<string, mixed>
     */
    private function specWithPathItemRef(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/reused' => ['$ref' => '#/components/pathItems/Reused']],
            'components' => [
                'pathItems' => [
                    'Reused' => [
                        'get' => ['tags' => ['Orders'], 'responses' => ['200' => ['description' => 'ok']]],
                        'delete' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]],
                    ],
                ],
            ],
        ];
    }

    public function test_resolves_path_item_ref_and_filters_inlined_operations(): void
    {
        // Granting only "Orders" keeps the referenced GET (inlined) and drops the
        // "Admin" DELETE — the $ref entry no longer silently vanishes.
        $filtered = $this->service()->filterForUser($this->specWithPathItemRef(), collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/reused'])
            ->and($filtered['paths']['/reused'])->toHaveKey('get')
            ->and($filtered['paths']['/reused'])->not->toHaveKey('delete')
            ->and($filtered['paths']['/reused'])->not->toHaveKey('$ref');
    }

    public function test_inlined_path_item_source_is_pruned_after_inlining(): void
    {
        // The inlined GET survives; the source components.pathItems.Reused
        // (holding the ungranted Admin DELETE) is now unreferenced and pruned,
        // so the ungranted operation can never be re-exposed.
        $filtered = $this->service()->filterForUser($this->specWithPathItemRef(), collect(['Orders']), collect([]));

        expect($filtered['paths']['/reused'])->toHaveKey('get')
            ->and($filtered['paths']['/reused'])->not->toHaveKey('delete')
            ->and($filtered['components']['pathItems'] ?? [])->toBe([]);
    }

    public function test_preserves_path_item_referenced_by_a_granted_operation_callback(): void
    {
        // A granted operation's callback $refs components.pathItems.CallbackDoc;
        // that pathItem must survive (valid callback docs), while an unreferenced
        // reuse source (OrphanReuse) is pruned.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/order' => ['post' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['onEvent' => ['{$request.body#/cb}' => ['$ref' => '#/components/pathItems/CallbackDoc']]],
            ]]],
            'components' => ['pathItems' => [
                'CallbackDoc' => ['post' => ['responses' => ['200' => ['description' => 'ack']]]],
                'OrphanReuse' => ['get' => ['responses' => ['200' => ['description' => 'x']]]],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['pathItems']))->toBe(['CallbackDoc']);
    }

    public function test_preserves_path_item_referenced_through_a_callback_component(): void
    {
        // The callback is a $ref to components.callbacks/OnEvent, which itself
        // refs components.pathItems/CallbackDoc. Reachability must follow the
        // callback-component hop so CallbackDoc survives (else a dangling ref),
        // while the unreferenced OrphanReuse is pruned.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/order' => ['post' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['onEvent' => ['$ref' => '#/components/callbacks/OnEvent']],
            ]]],
            'components' => [
                'callbacks' => ['OnEvent' => ['{$request.body#/cb}' => ['$ref' => '#/components/pathItems/CallbackDoc']]],
                'pathItems' => [
                    'CallbackDoc' => ['post' => ['responses' => ['200' => ['description' => 'ack']]]],
                    'OrphanReuse' => ['get' => ['responses' => ['200' => ['description' => 'x']]]],
                ],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['pathItems']))->toBe(['CallbackDoc'])
            ->and($filtered['components']['callbacks'])->toHaveKey('OnEvent');
    }

    public function test_path_item_ref_operations_are_grantable_by_endpoint(): void
    {
        $filtered = $this->service()->filterForUser($this->specWithPathItemRef(), collect([]), collect(['GET /reused']));

        expect(array_keys($filtered['paths']))->toBe(['/reused'])
            ->and($filtered['paths']['/reused'])->toHaveKey('get');
    }

    public function test_resolves_nested_path_item_refs_and_does_not_leak_via_pruning(): void
    {
        // A -> ($ref B) + granted GET; B holds an ungranted DELETE. A single-level
        // resolve would leave a "$ref: B" in the output, which pruning then follows
        // and keeps B whole — leaking the ungranted DELETE. The chained resolve
        // must inline only the granted GET and retain no pathItems $ref.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['$ref' => '#/components/pathItems/A']],
            'components' => [
                'pathItems' => [
                    'A' => [
                        '$ref' => '#/components/pathItems/B',
                        'get' => ['tags' => ['Orders'], 'responses' => ['200' => ['description' => 'ok']]],
                    ],
                    'B' => [
                        'delete' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]],
                    ],
                ],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/x'])
            ->and($filtered['paths']['/x'])->toHaveKey('get')
            ->and($filtered['paths']['/x'])->not->toHaveKey('delete')
            ->and($filtered['paths']['/x'])->not->toHaveKey('$ref')
            // Neither pathItems component (A/B) may survive: nothing references them.
            ->and($filtered['components']['pathItems'] ?? [])->toBe([]);
    }

    public function test_strips_unsupported_external_ref_but_keeps_granted_local_op(): void
    {
        // An external/unsupported path-item $ref alongside a granted local op:
        // the $ref must be stripped (invariant: no path-item $ref survives), the
        // granted GET inlined.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/mix' => [
                '$ref' => './external.yaml#/paths/~1foo',
                'get' => ['tags' => ['Orders'], 'responses' => ['200' => ['description' => 'ok']]],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/mix'])
            ->and($filtered['paths']['/mix'])->toHaveKey('get')
            ->and($filtered['paths']['/mix'])->not->toHaveKey('$ref');
    }

    public function test_drops_path_item_that_is_only_an_unsupported_ref(): void
    {
        // A path item that is ONLY an unresolvable external ref has no local
        // operation to filter, so after stripping the ref the entry is dropped —
        // even if an endpoint grant nominally targets its key.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/ext' => ['$ref' => 'https://other.example.com/spec.json#/paths/~1x']],
        ];

        $filtered = $this->service()->filterForUser($spec, collect([]), collect(['GET /ext']));

        expect($filtered['paths'])->toBe([]);
    }

    public function test_metadata_includes_referenced_path_item_operations(): void
    {
        $spec = $this->specWithPathItemRef();

        expect($this->service()->extractTags($spec))->toContain('Orders')->toContain('Admin')
            ->and(collect($this->service()->extractEndpoints($spec))->pluck('label')->all())
            ->toContain('GET /reused')->toContain('DELETE /reused');
    }

    public function test_keeps_owning_component_for_a_nested_pointer_ref(): void
    {
        // A $ref into a sub-schema (#/components/schemas/Pet/properties/id) must
        // keep the OWNING Pet schema reachable, not look up "Pet/properties/id".
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Pet/properties/id'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                'Unused' => ['type' => 'string'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Pet']);
    }

    public function test_example_data_refs_do_not_keep_components_alive(): void
    {
        // A literal "$ref" inside an `example` value is data, not a reference —
        // it must NOT keep an otherwise-unreachable schema alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Real'],
                    'example' => ['$ref' => '#/components/schemas/Secret'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Real' => ['type' => 'object'], 'Secret' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Real']);
    }

    public function test_real_example_object_ref_is_preserved(): void
    {
        // A genuine {$ref: #/components/examples/X} in an `examples` MAP is a real
        // reference and must survive; an unreferenced example is pruned.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'examples' => ['sample' => ['$ref' => '#/components/examples/Sample']],
                ]]]],
            ]]],
            'components' => ['examples' => ['Sample' => ['value' => ['id' => 1]], 'Unused' => ['value' => ['x' => 2]]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['examples']))->toBe(['Sample']);
    }

    public function test_drops_non_array_component_members_when_filtering(): void
    {
        // A scalar component member (e.g. an x-* extension) is unreachable and
        // must not survive into a non-admin filtered spec.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [],
            'components' => ['x-internal-note' => 'secret note', 'schemas' => ['Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect([]), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_keeps_root_security_when_a_granted_callback_operation_inherits_it(): void
    {
        // The only surviving top-level op declares its own security, but its
        // callback op has none -> it inherits root, so root security (and its
        // scheme) must be kept, not dropped.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'security' => [['ApiKeyAuth' => []]],
            'paths' => ['/order' => ['post' => [
                'tags' => ['Orders'],
                'security' => [['ApiKeyAuth' => []]],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['onEvent' => ['{$request.body#/id}' => ['post' => [
                    'responses' => ['200' => ['description' => 'ack']],
                ]]]],
            ]]],
            'components' => ['securitySchemes' => ['ApiKeyAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->toHaveKey('security')
            ->and(array_keys($filtered['components']['securitySchemes']))->toBe(['ApiKeyAuth']);
    }

    public function test_keeps_security_scheme_used_only_by_a_granted_callback(): void
    {
        // A granted operation's callback operation declares security: [{CallbackAuth}].
        // The scheme is referenced by name, so the reachability closure must keep
        // it (else a dangling security requirement); UnusedAuth is pruned.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/order' => ['post' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['onEvent' => ['{$request.body#/cb}' => ['post' => [
                    'security' => [['CallbackAuth' => []]],
                    'responses' => ['200' => ['description' => 'ack']],
                ]]]],
            ]]],
            'components' => ['securitySchemes' => [
                'CallbackAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'UnusedAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['securitySchemes']))->toBe(['CallbackAuth']);
    }

    // ---- B7: injectServers validation -------------------------------------

    public function test_inject_servers_skips_malformed_entries(): void
    {
        $out = $this->service()->injectServers(['openapi' => '3.1.0'], [
            ['url' => 'https://api.example.com', 'description' => 'Prod'],
            ['description' => 'no url'],
            ['url' => '   '],
            ['url' => 'https://staging.example.com'],
        ]);

        expect($out['servers'])->toBe([
            ['url' => 'https://api.example.com', 'description' => 'Prod'],
            ['url' => 'https://staging.example.com'],
        ]);
    }

    public function test_inject_servers_clears_upstream_servers_when_none_active(): void
    {
        // Admin deactivated all servers (empty/all-invalid list): the upstream's
        // own servers must be REMOVED, never shown to users.
        $spec = ['openapi' => '3.1.0', 'servers' => [['url' => 'https://upstream.internal/api']]];

        $emptied = $this->service()->injectServers($spec, []);
        expect($emptied)->not->toHaveKey('servers');

        $allInvalid = $this->service()->injectServers($spec, [['url' => 'not-a-url'], ['url' => '   ']]);
        expect($allInvalid)->not->toHaveKey('servers');
    }

    public function test_inject_servers_strips_nested_servers(): void
    {
        // Path-item and operation level `servers` override the top level, so they
        // must be stripped too — only the admin-approved top-level set survives.
        $spec = [
            'openapi' => '3.1.0',
            'paths' => ['/x' => [
                'servers' => [['url' => 'https://internal.upstream/path-level']],
                'get' => [
                    'servers' => [['url' => 'https://internal.upstream/op-level']],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ]],
        ];

        $out = $this->service()->injectServers($spec, [['url' => 'https://api.example.com']]);

        expect($out['servers'])->toBe([['url' => 'https://api.example.com']])
            ->and($out['paths']['/x'])->not->toHaveKey('servers')
            ->and($out['paths']['/x']['get'])->not->toHaveKey('servers');
    }

    public function test_inject_servers_strips_link_object_servers(): void
    {
        // OpenAPI Link Objects carry a singular `server` — it must be stripped too
        // (responses.*.links.*.server and components.links.*.server).
        $spec = [
            'openapi' => '3.1.0',
            'paths' => ['/x' => ['get' => [
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'next' => ['operationId' => 'getNext', 'server' => ['url' => 'https://internal.upstream/link']],
                ]]],
            ]]],
            'components' => ['links' => [
                'Shared' => ['operationId' => 'shared', 'server' => ['url' => 'https://internal.upstream/clink']],
            ]],
        ];

        $out = $this->service()->injectServers($spec, [['url' => 'https://api.example.com']]);

        expect($out['paths']['/x']['get']['responses']['200']['links']['next'])->not->toHaveKey('server')
            ->and($out['components']['links']['Shared'])->not->toHaveKey('server');
    }

    public function test_inject_servers_strips_link_servers_in_reusable_responses(): void
    {
        // A Link Object's `server` inside a reusable components.responses entry
        // must be stripped too (reachable via a surviving operation's $ref).
        $spec = [
            'openapi' => '3.1.0',
            'paths' => ['/x' => ['get' => ['responses' => ['201' => ['$ref' => '#/components/responses/Created']]]]],
            'components' => ['responses' => [
                'Created' => ['description' => 'created', 'links' => [
                    'next' => ['operationId' => 'getNext', 'server' => ['url' => 'https://internal.upstream/rlink']],
                ]],
            ]],
        ];

        $out = $this->service()->injectServers($spec, [['url' => 'https://api.example.com']]);

        expect($out['components']['responses']['Created']['links']['next'])->not->toHaveKey('server');
    }

    public function test_inject_servers_does_not_log_credentials_for_rejected_entries(): void
    {
        Log::spy();

        $this->service()->injectServers(['openapi' => '3.1.0'], [['url' => 'https://user:pass@api.example.com']]);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $blob = $message.' '.(string) json_encode($context);

            return ! str_contains($blob, 'user:pass') && ! str_contains($blob, 'pass@');
        })->once();
    }

    public function test_inject_servers_rejects_credential_bearing_url(): void
    {
        // A server URL with embedded userinfo would ship credentials to the
        // browser; it must be skipped like any other invalid entry.
        $out = $this->service()->injectServers(['openapi' => '3.1.0'], [
            ['url' => 'https://user:pass@api.example.com'],
            ['url' => 'https://clean.example.com'],
        ]);

        expect($out['servers'])->toBe([['url' => 'https://clean.example.com']]);
    }

    public function test_inject_servers_rejects_non_url_and_unsafe_scheme_values(): void
    {
        // Non-empty but invalid URLs (schemeless, javascript:, ftp:) must be
        // skipped just like empty ones, so a seed/import bypassing the
        // FormRequest can't inject a dangerous server into Scalar.
        $out = $this->service()->injectServers(['openapi' => '3.1.0'], [
            ['url' => 'not-a-url'],
            ['url' => 'javascript:alert(1)'],
            ['url' => 'ftp://files.example.com'],
            ['url' => '  https://api.example.com  '],
        ]);

        expect($out['servers'])->toBe([
            ['url' => 'https://api.example.com'],
        ]);
    }
}
