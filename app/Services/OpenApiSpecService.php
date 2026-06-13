<?php

declare(strict_types=1);

namespace App\Services;

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
            $request = Http::timeout(Config::integer('openapi.http_timeout', 8))
                ->retry(2, 200)
                ->acceptJson();

            // Optional authentication header towards the external OpenAPI server.
            $headerToken = config('openapi.auth_header.token');
            if (is_string($headerToken) && $headerToken !== '') {
                $request = $request->withHeaders([Config::string('openapi.auth_header.name') => $headerToken]);
            }

            $payload = $request->get($url)->throw()->json();
            $spec = is_array($payload) ? $payload : [];

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

        foreach ($this->asArray($spec['paths'] ?? null) as $pathItem) {
            foreach ($this->operations($pathItem) as $operation) {
                foreach ($this->asArray($operation['tags'] ?? null) as $name) {
                    if (is_string($name)) {
                        $tags[$name] = true;
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

        foreach ($this->asArray($spec['paths'] ?? null) as $path => $pathItem) {
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

        // Rebuild the paths rather than mutating the untrusted nested structure.
        $newPaths = [];
        foreach ($this->asArray($spec['paths'] ?? null) as $rawPath => $pathItem) {
            $path = (string) $rawPath;
            $kept = [];

            foreach ($this->asArray($pathItem) as $key => $value) {
                $verb = is_string($key) ? $key : '';

                // Non-operation keys (parameters/summary/$ref/servers): always preserved.
                if (! in_array($verb, self::HTTP_VERBS, true)) {
                    $kept[$key] = $value;

                    continue;
                }

                $operationTags = array_values(array_filter(
                    $this->asArray($this->asArray($value)['tags'] ?? null),
                    static fn (mixed $t): bool => is_string($t),
                ));
                $byTag = array_intersect_key($tagSet, array_flip($operationTags)) !== [];
                $byEndpoint = isset($endpointSet[strtoupper($verb).' '.$path]);

                if (! $byTag && ! $byEndpoint) {
                    continue; // drop this operation
                }

                $kept[$key] = $value;
                foreach ($operationTags as $name) {
                    $usedTags[$name] = true;
                }
            }

            // Keep the path only if at least one operation survived.
            if (array_intersect(array_keys($kept), self::HTTP_VERBS) !== []) {
                $newPaths[$path] = $kept;
            }
        }
        $spec['paths'] = $newPaths;

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
     * @param  list<array{url: string, description?: string|null}>  $servers
     * @return array<string, mixed>
     */
    public function injectServers(array $spec, array $servers): array
    {
        if ($servers !== []) {
            $spec['servers'] = $servers;
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

        $reachable = [];                                   // "schemas/Foo" => true
        $queue = $this->collectComponentRefs($spec['paths'] ?? []);

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
