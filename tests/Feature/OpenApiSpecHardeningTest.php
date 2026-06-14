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

    public function test_keeps_dynamic_ref_target_schema(): void
    {
        // OpenAPI 3.1 / JSON Schema 2020-12 $dynamicRef to a component must keep
        // that component reachable, like $ref.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$dynamicRef' => '#/components/schemas/Node'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Node' => ['type' => 'object'], 'Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Node']);
    }

    public function test_follows_callback_component_alias_during_pruning(): void
    {
        // A callback component that is a Reference Object (Alias -> Real) must
        // keep its target callback reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['post' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['cb' => ['$ref' => '#/components/callbacks/Alias']],
            ]]],
            'components' => ['callbacks' => [
                'Alias' => ['$ref' => '#/components/callbacks/Real'],
                'Real' => ['{$request.body#/u}' => ['post' => ['responses' => ['200' => ['description' => 'ack']]]]],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['callbacks']))->toContain('Alias')->toContain('Real');
    }

    public function test_anchor_declared_under_keyword_named_property_is_resolved(): void
    {
        // A $dynamicAnchor declared inside a schema PROPERTY named "links" must
        // still register as an anchor owner (property name, not keyword), so a
        // $dynamicRef to it keeps the holder reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Root'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Root' => ['type' => 'object', 'properties' => ['x' => ['$dynamicRef' => '#node']]],
                'Holder' => ['type' => 'object', 'properties' => ['links' => ['$dynamicAnchor' => 'node', 'type' => 'object']]],
                'Unused' => ['type' => 'string'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))
            ->toContain('Root')->toContain('Holder')->not->toContain('Unused');
    }

    public function test_callback_expression_keyed_like_keyword_is_walked(): void
    {
        // A callback whose expression key looks like a keyword ("x-cb"/"security")
        // must still be walked, so schemas used only by it survive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['post' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['security' => ['x-cb' => ['post' => [
                    'requestBody' => ['content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CbPayload'],
                    ]]],
                    'responses' => ['200' => ['description' => 'ack']],
                ]]]],
            ]]],
            'components' => ['schemas' => ['CbPayload' => ['type' => 'object'], 'Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['CbPayload']);
    }

    public function test_false_anchor_in_link_component_is_ignored(): void
    {
        // A $dynamicAnchor inside a components.links Link Object's requestBody is
        // data, not a real anchor — a $dynamicRef must not resurrect that link.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$dynamicRef' => '#node'],
                ]]]],
            ]]],
            'components' => ['links' => ['Hidden' => ['operationId' => 'x', 'requestBody' => ['$dynamicAnchor' => 'node']]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components'] ?? [])->toBe([]);
    }

    public function test_drops_link_with_relative_same_document_operationref(): void
    {
        // A relative same-document operationRef ("./openapi.json#/paths/...") to a
        // filtered-out op must be dropped (resolved by fragment), not kept as
        // external — otherwise the hidden path leaks.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'toAdmin' => ['operationRef' => './openapi.json#/paths/~1admin/get'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_drops_link_with_ref_and_hidden_sibling_operationref(): void
    {
        // A malformed link combining a surviving $ref with a hidden sibling
        // operationRef must be dropped (the sibling would leak the hidden path).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'operationId' => 'getA',
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'bad' => ['$ref' => '#/components/links/Safe', 'operationRef' => '#/paths/~1admin/get'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => ['links' => ['Safe' => ['operationId' => 'getA']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_drops_link_when_operationref_hidden_despite_valid_operationid(): void
    {
        // A malformed link with a valid operationId AND a hidden operationRef must
        // be dropped (the operationRef would leak the hidden path).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'operationId' => 'getA',
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'bad' => ['operationId' => 'getA', 'operationRef' => '#/paths/~1admin/get'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_drops_link_with_malformed_local_operationref(): void
    {
        // A local operationRef with no method segment can't resolve — drop the
        // link rather than leaking the path string.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'bad' => ['operationRef' => '#/paths/~1internal'],
                ]]],
            ]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_false_anchor_in_example_data_is_ignored(): void
    {
        // A $dynamicAnchor inside example DATA is not a real anchor, so a
        // $dynamicRef must not keep that component alive through it.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$dynamicRef' => '#node'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Hidden' => ['type' => 'object', 'example' => ['$dynamicAnchor' => 'node']],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components'] ?? [])->toBe([]);
    }

    public function test_link_request_body_ref_is_data_not_reachability(): void
    {
        // A Link Object's requestBody is a literal Any/expression — a $ref inside
        // it is data, so it must not keep an unreachable component alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'operationId' => 'getA',
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'self' => ['operationId' => 'getA', 'requestBody' => ['$ref' => '#/components/schemas/Secret']],
                ]]],
            ]]],
            'components' => ['schemas' => ['Secret' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components'] ?? [])->toBe([])
            ->and($filtered['paths']['/a']['get']['responses']['200']['links'])->toHaveKey('self');
    }

    public function test_keeps_dynamic_anchor_target_schema(): void
    {
        // $dynamicRef: "#node" (anchor form) must keep the component declaring
        // $dynamicAnchor: "node" reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$dynamicRef' => '#node'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Node' => ['$dynamicAnchor' => 'node', 'type' => 'object'],
                'Unused' => ['type' => 'string'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toContain('Node')->not->toContain('Unused');
    }

    public function test_external_component_ref_does_not_keep_local_component(): void
    {
        // An external URI ref with a local-looking fragment targets ANOTHER
        // document — it must not keep our same-named local component reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => 'https://schemas.example.com/common.json#/components/schemas/Internal'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Internal' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_resolves_relative_same_document_path_item_ref(): void
    {
        // A filename-prefixed same-document path-item ref must resolve and inline.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/reused' => ['$ref' => './openapi.json#/components/pathItems/Reused']],
            'components' => ['pathItems' => ['Reused' => [
                'get' => ['tags' => ['Orders'], 'responses' => ['200' => ['description' => 'ok']]],
            ]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/reused'])
            ->and($filtered['paths']['/reused'])->toHaveKey('get')
            ->and($filtered['paths']['/reused'])->not->toHaveKey('$ref');
    }

    public function test_drops_relative_component_link_ref_to_filtered_target(): void
    {
        // A relative component-link ref (./openapi.json#/components/links/X) whose
        // target link points at a filtered operation must be dropped, not kept.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'go' => ['$ref' => './openapi.json#/components/links/ToSecret'],
                    ]]],
                ]],
                '/secret' => ['get' => ['tags' => ['Admin'], 'operationId' => 'getSecret', 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => ['links' => ['ToSecret' => ['operationId' => 'getSecret']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_generic_ref_to_path_item_does_not_keep_it(): void
    {
        // A $ref to components.pathItems from a SCHEMA position (cross-type) must
        // not keep the unfiltered path item (with its ungranted operations).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/pathItems/Admin'],
                ]]]],
            ]]],
            'components' => ['pathItems' => ['Admin' => ['get' => [
                'tags' => ['Admin'],
                'responses' => ['200' => ['description' => 'secret']],
            ]]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components']['pathItems'] ?? [])->toBe([]);
    }

    public function test_ref_with_query_is_treated_as_external(): void
    {
        // A query distinguishes document representations, so a ref carrying a
        // query ("?variant=internal#/...") targets a different document and must
        // not keep our same-pointer local component.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '?variant=internal#/components/schemas/Secret'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Secret' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_drops_alias_component_link_with_hidden_sibling(): void
    {
        // A components.links alias whose alias target survives but which also has a
        // sibling operationRef to a filtered op must NOT be marked surviving.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'operationId' => 'getA',
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'go' => ['$ref' => '#/components/links/Alias'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => ['links' => [
                'Alias' => ['$ref' => '#/components/links/Safe', 'operationRef' => '#/paths/~1admin/get'],
                'Safe' => ['operationId' => 'getA'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_normalizes_uri_case_and_default_port(): void
    {
        // Scheme/host case and an explicit default port are equivalent, so a
        // link operationRef in that form still resolves to the upstream document.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'toAdmin' => ['operationRef' => 'HTTPS://SPECS.EXAMPLE.COM:443/openapi.json#/paths/~1admin/get'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_normalizes_absolute_operationref_to_current_document(): void
    {
        // An absolute operationRef that normalizes to the upstream document
        // (".../v1/../openapi.json") is same-document; a link to its filtered
        // /admin op must be dropped.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'toAdmin' => ['operationRef' => 'https://specs.example.com/v1/../openapi.json#/paths/~1admin/get'],
                    ]]],
                ]],
                '/admin' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_same_name_sibling_in_other_directory_is_external(): void
    {
        // Upstream is .../specs/v1/openapi.json; a "../common/openapi.json" ref
        // resolves to a DIFFERENT directory's file — external, not the current doc.
        config(['openapi.upstream_url' => 'https://api.example.com/specs/v1/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '../common/openapi.json#/components/schemas/Internal'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Internal' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_sibling_relative_ref_is_external(): void
    {
        // A relative ref to a SIBLING file ("./common.yaml#/...") targets another
        // document, so it must not keep our same-named local component reachable.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => './common.yaml#/components/schemas/Internal'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Internal' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_anchor_in_unreferenced_path_item_does_not_promote_it(): void
    {
        // A $dynamicAnchor declared in a schema nested in an UNREFERENCED
        // components.pathItems must NOT make a $dynamicRef pull the whole path
        // item (with its ungranted operations) into the filtered spec.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$dynamicRef' => '#node'],
                ]]]],
            ]]],
            'components' => ['pathItems' => ['Hidden' => ['get' => [
                'tags' => ['Admin'],
                'requestBody' => ['content' => ['application/json' => ['schema' => ['$dynamicAnchor' => 'node']]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components']['pathItems'] ?? [])->toBe([]);
    }

    public function test_keeps_component_for_relative_same_document_ref(): void
    {
        // A filename-prefixed same-document $ref ("./openapi.json#/components/...")
        // must keep its target component reachable, like the bare "#/components/" form.
        config(['openapi.upstream_url' => 'https://specs.example.com/openapi.json']);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => './openapi.json#/components/schemas/Pet'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Pet' => ['type' => 'object'], 'Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Pet']);
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

    public function test_extension_data_refs_do_not_keep_components_alive(): void
    {
        // A $ref inside an x-* specification extension is vendor data, not a real
        // reference — it must not keep an unreachable component alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'x-codeSamples' => [['$ref' => '#/components/schemas/Secret']],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => ['schemas' => ['Secret' => ['type' => 'object']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_schema_data_keyword_refs_do_not_keep_components_alive(): void
    {
        // $ref-shaped literals inside default/const/enum are data, not refs.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Real'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Real' => ['type' => 'object', 'default' => ['$ref' => '#/components/schemas/SecretA'], 'properties' => [
                    'k' => ['const' => ['$ref' => '#/components/schemas/SecretB']],
                ]],
                'SecretA' => ['type' => 'object'],
                'SecretB' => ['type' => 'object'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Real']);
    }

    public function test_default_response_is_walked_for_refs(): void
    {
        // responses.default is a real Response Object (not the schema `default`
        // data keyword), so its schema $ref must keep the component reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['default' => ['description' => 'err', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Err'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Err' => ['type' => 'object'], 'Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['Err']);
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

    public function test_schema_property_named_example_is_still_followed(): void
    {
        // A schema with a real PROPERTY named "example" (or "examples") whose
        // schema $refs a component must keep that component reachable — the
        // example-keyword skip must not apply to property names.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Wrapper'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Wrapper' => ['type' => 'object', 'properties' => [
                    'example' => ['$ref' => '#/components/schemas/Inner'],
                    'examples' => ['$ref' => '#/components/schemas/Inner2'],
                ]],
                'Inner' => ['type' => 'object'],
                'Inner2' => ['type' => 'object'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))
            ->toContain('Wrapper')->toContain('Inner')->toContain('Inner2');
    }

    public function test_security_in_example_data_does_not_keep_scheme_alive(): void
    {
        // A `security` key inside an `example` value is data, not a real Security
        // Requirement — it must not keep an unreferenced scheme alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'example' => ['security' => [['InternalAuth' => []]]],
                ]]]],
            ]]],
            'components' => ['securitySchemes' => ['InternalAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_reachable_example_value_refs_do_not_keep_components_alive(): void
    {
        // A reachable components.examples entry's `value` is data: a literal $ref
        // in it must NOT keep its target schema alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'examples' => ['sample' => ['$ref' => '#/components/examples/Sample']],
                ]]]],
            ]]],
            'components' => [
                'examples' => ['Sample' => ['value' => ['$ref' => '#/components/schemas/Secret']]],
                'schemas' => ['Secret' => ['type' => 'object']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['examples']))->toBe(['Sample'])
            ->and($filtered['components']['schemas'] ?? [])->toBe([]);
    }

    public function test_keeps_digit_only_security_scheme_name(): void
    {
        // A digit-only scheme name decodes to an int JSON key; it must still be
        // collected so its securityScheme isn't pruned (dangling auth otherwise).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'security' => [['123' => []]],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => ['securitySchemes' => ['123' => ['type' => 'http', 'scheme' => 'bearer']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->toHaveKey('security')
            ->and($filtered['components']['securitySchemes'])->toHaveKey('123')
            ->and($filtered['components']['securitySchemes'])->toHaveCount(1);
    }

    public function test_prunes_links_to_filtered_out_operations(): void
    {
        // /a (granted) has a response link to /secret (not granted). After
        // filtering, the link must be removed so /secret isn't leaked via the link.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'toSecret' => ['operationRef' => '#/paths/~1secret/get'],
                        'toSelf' => ['operationId' => 'getA'],
                    ]]],
                ]],
                '/secret' => ['get' => [
                    'tags' => ['Admin'],
                    'operationId' => 'getSecret',
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
        ];
        // /a.get also needs operationId getA to keep the self link.
        $spec['paths']['/a']['get']['operationId'] = 'getA';

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['paths']))->toBe(['/a'])
            ->and($filtered['paths']['/a']['get']['responses']['200']['links'])
            ->toHaveKey('toSelf')->not->toHaveKey('toSecret');
    }

    public function test_prunes_links_to_filtered_webhooks_and_in_callback_responses(): void
    {
        // Links can target a webhook via operationRef, and links also live inside
        // callback responses — both must be pruned when their target is filtered.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'toHook' => ['operationRef' => '#/webhooks/secretHook/post'],
                    ]]],
                    'callbacks' => ['cb' => ['{$request.body#/u}' => ['post' => [
                        'responses' => ['200' => ['description' => 'ack', 'links' => [
                            'cbToSecret' => ['operationRef' => '#/paths/~1secret/get'],
                        ]]],
                    ]]]],
                ]],
                '/secret' => ['get' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'webhooks' => ['secretHook' => ['post' => ['tags' => ['Admin'], 'responses' => ['200' => ['description' => 'ok']]]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([])
            ->and($filtered['paths']['/a']['get']['callbacks']['cb']['{$request.body#/u}']['post']['responses']['200']['links'])->toBe([]);
    }

    public function test_reprunes_components_orphaned_by_dropped_links(): void
    {
        // /a links to a hidden op via components.links.ToSecret, which carries a
        // $ref to schemas.Secret. The link is dropped; Secret must NOT survive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/a' => ['get' => [
                    'tags' => ['Orders'],
                    'responses' => ['200' => ['description' => 'ok', 'links' => [
                        'go' => ['$ref' => '#/components/links/ToSecret'],
                    ]]],
                ]],
                '/secret' => ['get' => ['tags' => ['Admin'], 'operationId' => 'getSecret', 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => [
                'links' => ['ToSecret' => [
                    'operationId' => 'getSecret',
                    'requestBody' => ['$ref' => '#/components/schemas/Secret'],
                ]],
                'schemas' => ['Secret' => ['type' => 'object']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([])
            ->and($filtered['components'] ?? [])->toBe([]);
    }

    public function test_preserves_surviving_component_link_alias_chain(): void
    {
        // A response link -> components.links.Alias -> components.links.Real, where
        // Real targets the surviving /a. The whole chain must be kept.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'operationId' => 'getA',
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'self' => ['$ref' => '#/components/links/Alias'],
                ]]],
            ]]],
            'components' => ['links' => [
                'Alias' => ['$ref' => '#/components/links/Real'],
                'Real' => ['operationId' => 'getA'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toHaveKey('self')
            ->and(array_keys($filtered['components']['links']))->toContain('Alias')->toContain('Real');
    }

    public function test_prunes_link_operationref_to_filtered_component_path_item(): void
    {
        // A link operationRef into components.pathItems whose target was pruned
        // (unreferenced) must be dropped, not kept as "external".
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'toHidden' => ['operationRef' => '#/components/pathItems/Hidden/get'],
                ]]],
            ]]],
            'components' => ['pathItems' => ['Hidden' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([]);
    }

    public function test_response_header_named_like_a_keyword_is_walked(): void
    {
        // A response header named "example" must be walked as a header name, not
        // the example keyword, so its schema $ref keeps the component reachable.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'headers' => [
                    'example' => ['schema' => ['$ref' => '#/components/schemas/HeaderValue']],
                ]]],
            ]]],
            'components' => ['schemas' => ['HeaderValue' => ['type' => 'string'], 'Unused' => ['type' => 'integer']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))->toBe(['HeaderValue']);
    }

    public function test_webhook_named_like_a_keyword_is_still_walked(): void
    {
        // A webhook named "security" must be walked as a name, not misread as the
        // security keyword — else schemas used only by it get pruned (dangling).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'webhooks' => ['security' => ['post' => [
                'tags' => ['Webhooks'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Payload'],
                ]]]],
            ]]],
            'components' => ['schemas' => ['Payload' => ['type' => 'object'], 'Unused' => ['type' => 'string']]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Webhooks']), collect([]));

        expect(array_keys($filtered['webhooks']))->toBe(['security'])
            ->and(array_keys($filtered['components']['schemas']))->toBe(['Payload']);
    }

    public function test_keeps_discriminator_mapping_target_schemas(): void
    {
        // discriminator.mapping values are schema refs (by URI or bare name) not
        // under a $ref key; the mapped concrete schemas must survive pruning.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/pet' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Pet'],
                ]]]],
            ]]],
            'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'discriminator' => ['propertyName' => 'type', 'mapping' => [
                    'dog' => '#/components/schemas/Dog',
                    'cat' => 'Cat',
                ]]],
                'Dog' => ['type' => 'object'],
                'Cat' => ['type' => 'object'],
                'Unused' => ['type' => 'string'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))
            ->toContain('Pet')->toContain('Dog')->toContain('Cat')->not->toContain('Unused');
    }

    public function test_follows_ref_from_a_root_security_scheme_alias(): void
    {
        // Root security names ApiKeyAuth, which is a Reference Object to BearerAuth.
        // A surviving op inherits root, so both schemes must survive (no dangling).
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'security' => [['ApiKeyAuth' => []]],
            'paths' => ['/order' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => ['securitySchemes' => [
                'ApiKeyAuth' => ['$ref' => '#/components/securitySchemes/BearerAuth'],
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->toHaveKey('security')
            ->and(array_keys($filtered['components']['securitySchemes']))
            ->toContain('ApiKeyAuth')->toContain('BearerAuth');
    }

    public function test_follows_ref_from_an_example_object_alias(): void
    {
        // An Example component that is a Reference Object (Alias -> Real) must keep
        // its target reachable, even though example `value` payloads are skipped.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/x' => ['get' => [
                'tags' => ['Orders'],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                    'examples' => ['sample' => ['$ref' => '#/components/examples/Alias']],
                ]]]],
            ]]],
            'components' => ['examples' => [
                'Alias' => ['$ref' => '#/components/examples/Real'],
                'Real' => ['value' => ['id' => 1]],
                'Unused' => ['value' => ['x' => 2]],
            ]],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['examples']))
            ->toContain('Alias')->toContain('Real')->not->toContain('Unused');
    }

    public function test_unreachable_path_item_does_not_keep_root_security(): void
    {
        // The only surviving op declares its own security; an UNREFERENCED
        // components.pathItems entry lacks security but is pruned, so it must not
        // keep the (now vacuous) root security/scheme metadata alive.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'security' => [['ApiKeyAuth' => []]],
            'paths' => ['/order' => ['post' => [
                'tags' => ['Orders'],
                'security' => [['ApiKeyAuth' => []]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => [
                'pathItems' => ['Unreferenced' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
                'securitySchemes' => ['ApiKeyAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered)->not->toHaveKey('security')
            ->and($filtered['components']['pathItems'] ?? [])->toBe([]);
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

    public function test_inject_servers_rejects_malformed_url_but_keeps_server_variables(): void
    {
        // parse_url tolerates a space in the host; filter_var rejects it. A
        // templated URL (OpenAPI server variable) must still be accepted.
        $out = $this->service()->injectServers(['openapi' => '3.1.0'], [
            ['url' => 'https://exa mple.com'],
            ['url' => 'https://{region}.api.example.com/v1'],
        ]);

        expect($out['servers'])->toBe([['url' => 'https://{region}.api.example.com/v1']]);
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

    public function test_malformed_link_ref_to_a_schema_is_dropped_and_does_not_leak_it(): void
    {
        // A Link Object whose $ref points at a NON-link component (a schema) is
        // malformed. It must be dropped from the response AND must NOT keep the
        // targeted schema reachable — otherwise an ungranted schema would leak
        // into the filtered spec via a bogus link reference.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'operationId' => 'getA',
                'responses' => ['200' => ['description' => 'ok', 'links' => [
                    'bogus' => ['$ref' => '#/components/schemas/Internal'],
                ]]],
            ]]],
            'components' => [
                'links' => ['AliasToInternal' => ['$ref' => '#/components/schemas/Internal']],
                'schemas' => ['Internal' => ['type' => 'object', 'description' => 'secret']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['paths']['/a']['get']['responses']['200']['links'])->toBe([])
            ->and($filtered['components'] ?? [])->not->toHaveKey('schemas')
            ->and($filtered['components']['links'] ?? [])->not->toHaveKey('AliasToInternal');
    }

    public function test_scope_named_ref_is_not_followed_as_a_component_reference(): void
    {
        // An OAuth2 scope literally named "$ref" is DATA (a scope-name => human
        // description map), not a component reference. It must not be chased as a
        // $ref, and the unrelated schema it names must still be pruned.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'operationId' => 'getA',
                'security' => [['oauth' => ['$ref']]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
            'components' => [
                'securitySchemes' => ['oauth' => [
                    'type' => 'oauth2',
                    'flows' => ['implicit' => [
                        'authorizationUrl' => 'https://example.com/auth',
                        'scopes' => ['$ref' => '#/components/schemas/Internal'],
                    ]],
                ]],
                'schemas' => ['Internal' => ['type' => 'object', 'description' => 'secret']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components']['securitySchemes'])->toHaveKey('oauth')
            ->and($filtered['components']['securitySchemes']['oauth']['flows']['implicit']['scopes'])
            ->toBe(['$ref' => '#/components/schemas/Internal'])
            ->and($filtered['components'] ?? [])->not->toHaveKey('schemas');
    }

    public function test_path_item_ref_to_a_callback_does_not_keep_the_callback(): void
    {
        // A Path Item $ref must resolve only to another Path Item. A callback path
        // item whose top-level $ref points at #/components/callbacks/Hidden is
        // malformed; the operation-bearing allowance for path-item positions must
        // NOT also admit callbacks, or the hidden callback (and its ungranted
        // operation + secret schema) would survive in the non-admin filtered spec.
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => ['/a' => ['get' => [
                'tags' => ['Orders'],
                'operationId' => 'getA',
                'responses' => ['200' => ['description' => 'ok']],
                'callbacks' => ['onEvent' => [
                    '{$request.body#/u}' => ['$ref' => '#/components/callbacks/Hidden'],
                ]],
            ]]],
            'components' => [
                'callbacks' => ['Hidden' => [
                    '{$request.body#/u}' => ['post' => [
                        'operationId' => 'hiddenOp',
                        'requestBody' => ['content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/Secret'],
                        ]]],
                        'responses' => ['200' => ['description' => 'ok']],
                    ]],
                ]],
                'schemas' => ['Secret' => ['type' => 'object', 'description' => 'secret']],
            ],
        ];

        $filtered = $this->service()->filterForUser($spec, collect(['Orders']), collect([]));

        expect($filtered['components']['callbacks'] ?? [])->not->toHaveKey('Hidden')
            ->and($filtered['components'] ?? [])->not->toHaveKey('schemas');
    }
}
