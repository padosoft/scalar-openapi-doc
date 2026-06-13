<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidOpenApiSpecException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central service for fetching, filtering and normalising the external OpenAPI
 * specification.
 *
 * Security principle: filtering is ALWAYS server-side, driven by the caller's
 * authorization (passed in as $isAdmin / the granted tag+endpoint collections).
 * This service never reads the authenticated user itself — the controller
 * decides and passes the decision in, keeping the service pure and testable.
 *
 * The spec is untrusted external JSON, so every nested access is type-guarded:
 * a malformed value is treated as absent rather than throwing.
 */
final class OpenApiSpecService
{
    /**
     * OpenAPI keys recognised as operations (HTTP verbs).
     *
     * @var list<string>
     */
    private const HTTP_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    // ---------------------------------------------------------------------
    // Upstream fetch + cache
    // ---------------------------------------------------------------------

    /**
     * Returns the raw spec from cache, fetching from upstream if absent.
     *
     * @return array<string, mixed>
     */
    public function fetchRaw(): array
    {
        $cached = Cache::get($this->cacheKey());

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        return $this->refreshFromUpstream();
    }

    /**
     * Clears the OpenAPI spec cache.
     *
     * By default keeps the "stale" copy as a safety net in case the upstream is
     * unreachable right after the flush. $actorId is logged for audit context;
     * the caller (controller/command) supplies it — the service stays auth-free.
     */
    public function flushCache(bool $includeStale = false, ?int $actorId = null): void
    {
        Cache::forget($this->cacheKey());

        if ($includeStale) {
            Cache::forget($this->staleKey());
        }

        Log::info('OpenAPI spec cache flushed', [
            'by_user_id' => $actorId,
            'include_stale' => $includeStale,
        ]);
    }

    /**
     * Downloads the spec from upstream, populates the cache (+ stale copy) and
     * returns it. Serves the stale copy on upstream failure (stale-on-error).
     *
     * @return array<string, mixed>
     */
    private function refreshFromUpstream(): array
    {
        $configuredUrl = config('openapi.upstream_url');
        $url = is_string($configuredUrl) ? $configuredUrl : '';

        try {
            // SSRF guard: validate scheme/host BEFORE any network call (B3/B4).
            $this->assertAllowedUrl($url);

            $request = Http::timeout(Config::integer('openapi.http_timeout', 8))
                ->retry(2, 200)
                // Don't follow redirects: the SSRF allow-list is checked on the
                // configured URL only, so a redirect could escape it. The upstream
                // must serve the spec directly.
                ->withoutRedirecting()
                ->acceptJson();

            // Optional authentication header towards the external OpenAPI server.
            $headerToken = config('openapi.auth_header.token');
            if (is_string($headerToken) && $headerToken !== '') {
                $request = $request->withHeaders([Config::string('openapi.auth_header.name') => $headerToken]);
            }

            $payload = $request->get($url)->throw()->json();
            $spec = is_array($payload) ? $payload : [];

            // Anti cache-poisoning: only cache a payload that looks like an
            // OpenAPI document (B3). Otherwise fall through to the stale copy.
            $this->assertValidSpec($spec);

            Cache::put($this->cacheKey(), $spec, Config::integer('openapi.cache_ttl', 3600));
            Cache::forever($this->staleKey(), $spec);

            /** @var array<string, mixed> $spec */
            return $spec;
        } catch (Throwable $e) {
            Log::error('OpenAPI upstream spec fetch failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            // Stale-on-error: serve the last known-good copy if we have one.
            $stale = Cache::get($this->staleKey());
            if (is_array($stale)) {
                /** @var array<string, mixed> $stale */
                return $stale;
            }

            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Metadata extraction (for the admin select inputs)
    // ---------------------------------------------------------------------

    /**
     * Unique tags (from top-level + operations), sorted alphabetically.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public function extractTags(array $spec): array
    {
        $tags = [];

        foreach ($this->asArray($spec['tags'] ?? null) as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? null) : null;
            if (is_string($name)) {
                $tags[$name] = true;
            }
        }

        // Tags from operations under both paths and webhooks (3.1), so a
        // webhook-only tag is still offered in the admin grant UI.
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                foreach ($this->operations($pathItem) as $operation) {
                    foreach ($this->asArray($operation['tags'] ?? null) as $name) {
                        if (is_string($name)) {
                            $tags[$name] = true;
                        }
                    }
                }
            }
        }

        $names = array_keys($tags);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Unique endpoints (one entry per operation), sorted by path then method.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array{method: string, path: string, label: string, summary: string|null}>
     */
    public function extractEndpoints(array $spec): array
    {
        $endpoints = [];

        // Endpoints from both paths and webhooks (a webhook's key is used as its
        // "path", matching how filterForUser resolves "METHOD key" grants).
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $path => $pathItem) {
                $path = (string) $path;
                foreach ($this->operations($pathItem) as $method => $operation) {
                    $upper = strtoupper($method);
                    $summary = $operation['summary'] ?? null;
                    $endpoints[] = [
                        'method' => $upper,
                        'path' => $path,
                        'label' => $upper.' '.$path,
                        'summary' => is_string($summary) ? $summary : null,
                    ];
                }
            }
        }

        usort(
            $endpoints,
            static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]
        );

        return $endpoints;
    }

    // ---------------------------------------------------------------------
    // Per-user filtering
    // ---------------------------------------------------------------------

    /**
     * Filters the spec by the tags/endpoints granted to the user (UNION).
     *
     * @param  array<string, mixed>  $spec
     * @param  Collection<int, string>  $allowedTags  e.g. ["Orders", "Products"]
     * @param  Collection<int, string>  $allowedEndpoints  canonical "METHOD path", e.g. "GET /orders/{id}"
     * @param  bool  $isAdmin  the caller resolves this; admins bypass the filter when admin_sees_all is on
     * @return array<string, mixed>
     */
    public function filterForUser(array $spec, Collection $allowedTags, Collection $allowedEndpoints, bool $isAdmin = false): array
    {
        // Admin bypass: full spec (controlled by config, no hardcoded role name).
        if ($isAdmin && Config::boolean('openapi.admin_sees_all', true)) {
            return $spec;
        }

        $tagSet = array_flip($allowedTags->values()->all());
        $endpointSet = array_flip($allowedEndpoints->values()->all());
        $usedTags = [];

        // Rebuild paths AND webhooks (OpenAPI 3.1) with the same rules, so a
        // non-admin never receives ungranted webhook operations either.
        $spec['paths'] = $this->filterPathItemMap($this->asArray($spec['paths'] ?? null), $tagSet, $endpointSet, $usedTags);

        if (isset($spec['webhooks'])) {
            $webhooks = $this->filterPathItemMap($this->asArray($spec['webhooks']), $tagSet, $endpointSet, $usedTags);
            if ($webhooks === []) {
                unset($spec['webhooks']);
            } else {
                $spec['webhooks'] = $webhooks;
            }
        }

        // Top-level tag cleanup.
        if (isset($spec['tags']) && is_array($spec['tags'])) {
            $spec['tags'] = array_values(array_filter(
                $spec['tags'],
                static function (mixed $tag) use ($usedTags): bool {
                    return is_array($tag)
                        && isset($tag['name'])
                        && is_string($tag['name'])
                        && isset($usedTags[$tag['name']]);
                }
            ));
        }

        // Prune orphan components.
        if (Config::boolean('openapi.prune_components', true)) {
            $spec = $this->pruneComponents($spec);
        }

        return $spec;
    }

    /**
     * Overrides the servers array (Scalar playground environment dropdown).
     *
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, mixed>
     */
    public function injectServers(array $spec, array $servers): array
    {
        // Validate each entry (B7): a server needs a non-empty URL; description is
        // optional. Malformed entries are skipped with a warning, never breaking
        // the spec the browser receives.
        $valid = [];
        foreach ($servers as $server) {
            $url = $server['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                Log::warning('Skipping invalid OpenAPI server entry (missing/empty url)', ['server' => $server]);

                continue;
            }

            $entry = ['url' => $url];
            $description = $server['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $entry['description'] = $description;
            }
            $valid[] = $entry;
        }

        if ($valid !== []) {
            $spec['servers'] = $valid;
        }

        return $spec;
    }

    // ---------------------------------------------------------------------
    // Components pruning (transitive closure of $ref)
    // ---------------------------------------------------------------------

    /**
     * Removes from 'components' everything not reachable from the surviving paths.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function pruneComponents(array $spec): array
    {
        $components = $spec['components'] ?? null;
        if (! is_array($components)) {
            return $spec;
        }

        // Seed reachability from $refs under paths AND webhooks (path-item-level
        // $refs are covered too — collectComponentRefs walks the whole subtree).
        $reachable = [];                                   // "schemas/Foo" => true
        $queue = [
            ...$this->collectComponentRefs($spec['paths'] ?? []),
            ...$this->collectComponentRefs($spec['webhooks'] ?? []),
        ];

        // securitySchemes are referenced by NAME via `security` (not by $ref), so
        // seed them explicitly from the surviving operations + the root security.
        foreach ($this->securitySchemeNames($spec) as $schemeName) {
            $queue[] = 'securitySchemes/'.$schemeName;
        }

        while ($queue !== []) {
            $ref = array_pop($queue);
            if (isset($reachable[$ref])) {
                continue;
            }
            $reachable[$ref] = true;

            [$type, $name] = array_pad(explode('/', $ref, 2), 2, null);
            if (! is_string($type) || ! is_string($name)) {
                continue;
            }
            $bucket = $components[$type] ?? null;
            $component = is_array($bucket) ? ($bucket[$name] ?? null) : null;
            if ($component === null) {
                continue;
            }

            foreach ($this->collectComponentRefs($component) as $child) {
                if (! isset($reachable[$child])) {
                    $queue[] = $child;
                }
            }
        }

        // Rebuild components keeping only reachable entries.
        $pruned = [];
        foreach ($components as $type => $entries) {
            if (! is_array($entries)) {
                $pruned[$type] = $entries;

                continue;
            }
            $kept = [];
            foreach ($entries as $name => $entry) {
                if (isset($reachable[((string) $type).'/'.((string) $name)])) {
                    $kept[$name] = $entry;
                }
            }
            if ($kept !== []) {
                $pruned[$type] = $kept;
            }
        }

        if ($pruned === []) {
            unset($spec['components']);
        } else {
            $spec['components'] = $pruned;
        }

        return $spec;
    }

    /**
     * Collects internal component references ("schemas/Foo") present in a node.
     *
     * @return list<string>
     */
    private function collectComponentRefs(mixed $node): array
    {
        $refs = [];
        $prefix = '#/components/';

        $walk = static function (mixed $value) use (&$walk, &$refs, $prefix): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === '$ref' && is_string($child) && str_starts_with($child, $prefix)) {
                    $refs[] = substr($child, strlen($prefix));

                    continue;
                }
                $walk($child);
            }
        };

        $walk($node);

        return $refs;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * A path item's HTTP-verb operations only (skips parameters/summary/$ref and
     * non-array operation values). Keyed by lowercase verb.
     *
     * @return array<string, array<string, mixed>>
     */
    private function operations(mixed $pathItem): array
    {
        $operations = [];

        foreach ($this->asArray($pathItem) as $method => $operation) {
            if (is_string($method) && in_array($method, self::HTTP_VERBS, true) && is_array($operation)) {
                /** @var array<string, mixed> $operation */
                $operations[$method] = $operation;
            }
        }

        return $operations;
    }

    /**
     * Security scheme names referenced by the spec — from the root `security`
     * and from each operation's `security` in paths + webhooks. These reference
     * `components.securitySchemes.<name>` by name, not by $ref.
     *
     * @param  array<array-key, mixed>  $spec
     * @return list<string>
     */
    private function securitySchemeNames(array $spec): array
    {
        $names = [];

        $collect = function (mixed $security) use (&$names): void {
            foreach ($this->asArray($security) as $requirement) {
                foreach ($this->asArray($requirement) as $schemeName => $scopes) {
                    if (is_string($schemeName)) {
                        $names[$schemeName] = true;
                    }
                }
            }
        };

        $collect($spec['security'] ?? null);

        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                foreach ($this->operations($pathItem) as $operation) {
                    $collect($operation['security'] ?? null);
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Filters a paths-like map (paths or webhooks): drops operations not granted
     * by tag (UNION) or by explicit "METHOD key" endpoint, preserves non-operation
     * keys, and drops entries left with no surviving operation. Records used tags.
     *
     * @param  array<array-key, mixed>  $items
     * @param  array<string, int>  $tagSet
     * @param  array<string, int>  $endpointSet
     * @param  array<string, true>  $usedTags  (by-ref accumulator)
     * @return array<string, mixed>
     */
    private function filterPathItemMap(array $items, array $tagSet, array $endpointSet, array &$usedTags): array
    {
        $result = [];

        foreach ($items as $rawKey => $pathItem) {
            $key = (string) $rawKey;
            $kept = [];

            foreach ($this->asArray($pathItem) as $field => $value) {
                $verb = is_string($field) ? $field : '';

                // Non-operation keys (parameters/summary/$ref/servers): preserved.
                if (! in_array($verb, self::HTTP_VERBS, true)) {
                    $kept[$field] = $value;

                    continue;
                }

                $operationTags = array_values(array_filter(
                    $this->asArray($this->asArray($value)['tags'] ?? null),
                    static fn (mixed $t): bool => is_string($t),
                ));
                $byTag = array_intersect_key($tagSet, array_flip($operationTags)) !== [];
                $byEndpoint = isset($endpointSet[strtoupper($verb).' '.$key]);

                if (! $byTag && ! $byEndpoint) {
                    continue; // drop this operation
                }

                $kept[$field] = $value;
                foreach ($operationTags as $name) {
                    $usedTags[$name] = true;
                }
            }

            // Keep the entry only if at least one operation survived.
            if (array_intersect(array_keys($kept), self::HTTP_VERBS) !== []) {
                $result[$key] = $kept;
            }
        }

        return $result;
    }

    /**
     * SSRF guard: the upstream URL must parse, use an allowed scheme, and (when
     * a host allow-list is configured) target an allowed host.
     */
    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidOpenApiSpecException("OpenAPI upstream URL is invalid: [{$url}].");
        }

        $scheme = strtolower((string) $parts['scheme']);
        /** @var list<string> $schemes */
        $schemes = config('openapi.allowed_schemes', ['https']);
        if (! in_array($scheme, array_map('strtolower', $schemes), true)) {
            throw new InvalidOpenApiSpecException("OpenAPI upstream scheme [{$scheme}] is not allowed.");
        }

        $host = strtolower((string) $parts['host']);
        /** @var list<string> $hosts */
        $hosts = config('openapi.allowed_hosts', []);
        if ($hosts !== [] && ! in_array($host, array_map('strtolower', $hosts), true)) {
            throw new InvalidOpenApiSpecException("OpenAPI upstream host [{$host}] is not allowed.");
        }
    }

    /**
     * Anti cache-poisoning: a usable spec must declare `openapi` (string) +
     * `info` (object) and expose at least one operation container
     * (`paths`/`webhooks`/`components`). Anything else is not cached.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private function assertValidSpec(array $spec): void
    {
        $hasVersion = isset($spec['openapi']) && is_string($spec['openapi']) && $spec['openapi'] !== '';
        $hasInfo = isset($spec['info']) && is_array($spec['info']);
        $hasContainer = is_array($spec['paths'] ?? null)
            || is_array($spec['webhooks'] ?? null)
            || is_array($spec['components'] ?? null);

        if (! $hasVersion || ! $hasInfo || ! $hasContainer) {
            throw new InvalidOpenApiSpecException(
                'Upstream payload is not a valid OpenAPI document (missing openapi/info or any paths/webhooks/components).'
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function cacheKey(): string
    {
        return Config::string('openapi.cache_key', 'openapi:spec:raw');
    }

    private function staleKey(): string
    {
        return Config::string('openapi.stale_key', 'openapi:spec:stale');
    }
}
