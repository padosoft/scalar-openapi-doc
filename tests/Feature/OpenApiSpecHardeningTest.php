<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\InvalidOpenApiSpecException;
use App\Services\OpenApiSpecService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->and(array_keys($filtered['components']['schemas']))->toBe(['OrderEvent']);
    }

    public function test_keeps_only_granted_webhook_and_drops_secured_path(): void
    {
        $filtered = $this->service()->filterForUser($this->spec31(), collect(['Webhooks']), collect([]));

        expect($filtered['paths'])->toBe([])
            ->and(array_keys($filtered['webhooks']))->toBe(['newOrder'])
            ->and(array_keys($filtered['components']['schemas']))->toBe(['WebhookPayload']);
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

        expect($filtered)->not->toHaveKey('components');
    }

    public function test_prune_components_off_keeps_all_components(): void
    {
        config(['openapi.prune_components' => false]);

        $filtered = $this->service()->filterForUser($this->spec31(), collect(['Orders']), collect([]));

        expect(array_keys($filtered['components']['schemas']))
            ->toContain('OrderEvent')->toContain('Orphan')->toContain('WebhookPayload');
    }

    // ---- metadata includes webhooks (grant UI consistency) ----------------

    public function test_extract_tags_includes_webhook_tags(): void
    {
        // The 3.1 fixture's "Webhooks" tag exists only on a webhook operation.
        expect($this->service()->extractTags($this->spec31()))->toContain('Webhooks')->toContain('Orders');
    }

    public function test_extract_endpoints_includes_webhook_operations(): void
    {
        $labels = collect($this->service()->extractEndpoints($this->spec31()))->pluck('label')->all();

        expect($labels)->toContain('GET /secure')->toContain('POST newOrder');
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
