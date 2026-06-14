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

    /**
     * Namespace prefix for a webhook operation's canonical endpoint "path".
     *
     * OpenAPI requires path keys to start with "/", but webhook keys are
     * arbitrary strings — so a webhook could be keyed identically to a real path
     * (e.g. both "/orders"). Prefixing webhook grant keys keeps the two address
     * spaces disjoint, so a "POST /orders" path grant can never match a webhook
     * keyed "/orders" (and vice-versa).
     */
    private const WEBHOOK_GRANT_PREFIX = 'webhook:';

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
        // webhook-only tag is still offered in the admin grant UI. Path-item
        // $refs (components.pathItems reuse) are resolved so their tags count too.
        $pathItemComponents = $this->pathItemComponents($spec);
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                foreach ($this->operations($this->resolvePathItem($pathItem, $pathItemComponents)) as $operation) {
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

        // Endpoints from both paths and webhooks. A webhook's grant "path" is
        // namespaced (WEBHOOK_GRANT_PREFIX) so it can never collide with a real
        // path keyed identically — matching how filterForUser resolves grants.
        // Path-item $refs are resolved so reused operations are grantable too.
        $pathItemComponents = $this->pathItemComponents($spec);
        foreach (['paths', 'webhooks'] as $container) {
            $isWebhook = $container === 'webhooks';
            foreach ($this->asArray($spec[$container] ?? null) as $key => $pathItem) {
                $key = (string) $key;
                $grantPath = $this->canonicalEndpointPath($container, $key);
                foreach ($this->operations($this->resolvePathItem($pathItem, $pathItemComponents)) as $method => $operation) {
                    $upper = strtoupper($method);
                    $summary = $operation['summary'] ?? null;
                    $endpoints[] = [
                        'method' => $upper,
                        'path' => $grantPath,
                        'label' => $upper.' '.$key.($isWebhook ? ' (webhook)' : ''),
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
        $pathItemComponents = $this->pathItemComponents($spec);

        // Rebuild paths AND webhooks (OpenAPI 3.1) with the same rules, so a
        // non-admin never receives ungranted webhook operations either.
        $spec['paths'] = $this->filterPathItemMap($this->asArray($spec['paths'] ?? null), $tagSet, $endpointSet, $usedTags, 'paths', $pathItemComponents);

        if (isset($spec['webhooks'])) {
            $webhooks = $this->filterPathItemMap($this->asArray($spec['webhooks']), $tagSet, $endpointSet, $usedTags, 'webhooks', $pathItemComponents);
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

        // Root `security` applies only to operations that don't override it. If
        // no surviving operation inherits it, the requirement is vacuous — and
        // would dangle (referencing a securityScheme that pruning then removes),
        // yielding an invalid spec. Drop it in that case.
        if (isset($spec['security']) && ! $this->inheritsRootSecurity($spec)) {
            unset($spec['security']);
        }

        // Always prune unreachable components from the filtered spec (security
        // invariant: never ship the definitions of ungranted operations/schemas,
        // and never leave a dangling $ref). The closure follows callbacks →
        // pathItems, so a pathItem reused through a granted operation's callback
        // (inline or via a components.callbacks ref) is correctly preserved.
        $spec = $this->pruneComponents($spec);

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
        // Validate each entry (B7): a server needs a syntactically valid absolute
        // http(s) URL; description is optional. Malformed entries (missing/empty,
        // or non-URL values like "not-a-url" / "javascript:alert(1)" that could
        // bypass the FormRequest via a seed/import) are skipped with a warning,
        // never breaking the spec — or injecting a dangerous URL into the
        // playground dropdown Scalar renders.
        $valid = [];
        foreach ($servers as $server) {
            $url = $server['url'] ?? null;
            if (! is_string($url) || ! $this->isValidServerUrl($url)) {
                Log::warning('Skipping invalid OpenAPI server entry (missing/empty/malformed url)', ['server' => $server]);

                continue;
            }

            $entry = ['url' => trim($url)];
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
     * Removes from 'components' everything not reachable (transitively) from the
     * surviving paths/webhooks.
     *
     * Pruning is ALWAYS applied to a filtered (non-admin) spec — it is a security
     * invariant, not a convenience: retaining unreachable components would either
     * leak the definitions of ungranted operations/schemas or leave dangling
     * $refs. The reachability closure follows every component ref, including
     * callbacks → pathItems, so a path item reused through a granted operation's
     * callback is preserved while an orphaned reuse source is dropped.
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
        // securitySchemes are referenced by NAME via `security` (not by $ref), so
        // they are collected separately and seeded from operations + root.
        $reachable = [];                                   // "schemas/Foo" => true
        $queue = [
            ...$this->collectComponentRefs($spec['paths'] ?? []),
            ...$this->collectComponentRefs($spec['webhooks'] ?? []),
            ...$this->collectSecuritySchemeRefs($spec['paths'] ?? []),
            ...$this->collectSecuritySchemeRefs($spec['webhooks'] ?? []),
        ];
        foreach ($this->securityRequirementSchemes($spec['security'] ?? null) as $schemeName) {
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

            // Follow both $ref children AND security-scheme names declared inside
            // this reachable component (e.g. a callback/pathItem operation's
            // `security`), so callback-only schemes are kept, not pruned.
            foreach ([...$this->collectComponentRefs($component), ...$this->collectSecuritySchemeRefs($component)] as $child) {
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
     * Collects the OWNING component references ("schemas/Foo") present in a node.
     *
     * A $ref may be a JSON Pointer into a component (e.g.
     * "#/components/schemas/Pet/properties/id" or ".../Pet/$defs/Id"); only the
     * first segment after the bucket is the component key, so we normalise to
     * "schemas/Pet" — otherwise the owning component is never marked reachable
     * and gets pruned, leaving a dangling $ref.
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
                    $segments = explode('/', substr($child, strlen($prefix)));
                    if (count($segments) >= 2) {
                        $refs[] = $segments[0].'/'.$segments[1]; // owning "type/name"
                    }

                    continue;
                }
                $walk($child);
            }
        };

        $walk($node);

        return $refs;
    }

    /**
     * Collects "securitySchemes/<name>" for every scheme named in any `security`
     * requirement found anywhere within a node (operations, callback operations,
     * reusable path items). Security schemes are referenced by NAME, not $ref, so
     * the reachability closure must collect them separately.
     *
     * @return list<string>
     */
    private function collectSecuritySchemeRefs(mixed $node): array
    {
        $refs = [];

        $walk = function (mixed $value) use (&$walk, &$refs): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'security') {
                    foreach ($this->securityRequirementSchemes($child) as $name) {
                        $refs[] = 'securitySchemes/'.$name;
                    }

                    continue; // `security` holds requirement data, not nested refs
                }
                $walk($child);
            }
        };

        $walk($node);

        return $refs;
    }

    /**
     * Scheme names from a Security Requirement array ([{Scheme: [scopes]}, …]).
     *
     * @return list<string>
     */
    private function securityRequirementSchemes(mixed $security): array
    {
        $names = [];
        foreach ($this->asArray($security) as $requirement) {
            foreach ($this->asArray($requirement) as $schemeName => $scopes) {
                if (is_string($schemeName)) {
                    $names[] = $schemeName;
                }
            }
        }

        return $names;
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
     * Whether at least one surviving operation (in paths/webhooks) inherits the
     * root `security` — i.e. lacks its own `security` key. An operation that
     * declares `security` (including `security: []`, an explicit opt-out) does
     * not inherit root; one without the key does.
     *
     * @param  array<array-key, mixed>  $spec
     */
    private function inheritsRootSecurity(array $spec): bool
    {
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                foreach ($this->operations($pathItem) as $operation) {
                    if (! array_key_exists('security', $operation)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Filters a paths-like map (paths or webhooks): drops operations not granted
     * by tag (UNION) or by explicit "METHOD key" endpoint, preserves non-operation
     * keys, and drops entries left with no surviving operation. Records used tags.
     *
     * A path-item Reference Object ({"$ref": "#/components/pathItems/X"}, 3.1
     * reuse) is resolved first, then its operations are filtered and inlined —
     * so a granted operation defined via a shared path item survives, and an
     * ungranted one can't leak through the still-complete referenced component.
     *
     * @param  array<array-key, mixed>  $items
     * @param  array<string, int>  $tagSet
     * @param  array<string, int>  $endpointSet
     * @param  array<string, true>  $usedTags  (by-ref accumulator)
     * @param  'paths'|'webhooks'  $container  namespaces webhook endpoint grants
     * @param  array<array-key, mixed>  $pathItemComponents  components.pathItems (for $ref resolution)
     * @return array<string, mixed>
     */
    private function filterPathItemMap(array $items, array $tagSet, array $endpointSet, array &$usedTags, string $container, array $pathItemComponents): array
    {
        $result = [];

        foreach ($items as $rawKey => $pathItem) {
            $key = (string) $rawKey;
            $kept = [];

            foreach ($this->resolvePathItem($pathItem, $pathItemComponents) as $field => $value) {
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
                $byEndpoint = isset($endpointSet[strtoupper($verb).' '.$this->canonicalEndpointPath($container, $key)]);

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
     * SSRF guard: the upstream URL must parse, use an allowed scheme, and target
     * an allowed host. Host validation is ALWAYS enforced — when the configured
     * allow-list is empty, it falls back to the configured upstream URL's own
     * host (never "any host"), and fails closed if no host can be resolved.
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

        // Empty allow-list = lock to the configured upstream host, as documented
        // in config/openapi.php — NOT "trust every host". Fail closed if neither
        // a list nor a resolvable upstream host is available.
        if ($hosts === []) {
            $configuredUpstream = config('openapi.upstream_url');
            $upstreamHost = is_string($configuredUpstream) ? parse_url($configuredUpstream, PHP_URL_HOST) : null;
            $hosts = is_string($upstreamHost) && $upstreamHost !== '' ? [$upstreamHost] : [];
        }

        if ($hosts === [] || ! in_array($host, array_map('strtolower', $hosts), true)) {
            throw new InvalidOpenApiSpecException("OpenAPI upstream host [{$host}] is not allowed.");
        }
    }

    /**
     * The components.pathItems map (OpenAPI 3.1 reusable path items), or [].
     *
     * @param  array<array-key, mixed>  $spec
     * @return array<array-key, mixed>
     */
    private function pathItemComponents(array $spec): array
    {
        return $this->asArray($this->asArray($spec['components'] ?? null)['pathItems'] ?? null);
    }

    /**
     * Resolves a path-item Reference Object ({"$ref": "#/components/pathItems/X"},
     * OpenAPI 3.1 reuse) to the referenced path item, so its operations can be
     * filtered and enumerated. Sibling keys take precedence over the target.
     *
     * The whole `$ref` chain is followed (cycle-safe) and NO path-item `$ref`
     * survives in the result: a nested, dangling, cyclic, malformed, OR
     * external/unsupported (non-`#/components/pathItems/`) ref is dropped. This
     * is essential — if a `#/components/pathItems/…` ref leaked into the filtered
     * output, pruneComponents() would follow it and keep that entire (unfiltered)
     * path item; and any other surviving path-item ref could point the response
     * at content that was never filtered. We inline only resolved operations.
     *
     * @param  array<array-key, mixed>  $pathItemComponents
     * @return array<array-key, mixed>
     */
    private function resolvePathItem(mixed $pathItem, array $pathItemComponents): array
    {
        $item = $this->asArray($pathItem);
        $prefix = '#/components/pathItems/';
        $seen = [];

        // Collect each link of the $ref chain as a precedence layer (closest
        // first) WITHOUT its own $ref. Invariant: NO path-item $ref survives —
        // a malformed / external / unsupported / cyclic ref is simply not
        // followed, so the filtered response can never point at unfiltered (or
        // pruning-reachable) content.
        $layers = [];
        while (true) {
            $ref = $item['$ref'] ?? null;
            unset($item['$ref']);
            $layers[] = $item;

            if (! is_string($ref) || ! str_starts_with($ref, $prefix)) {
                break; // no further (resolvable path-item) ref
            }

            $name = substr($ref, strlen($prefix));
            if (isset($seen[$name])) {
                break; // cycle
            }
            $seen[$name] = true;

            $target = $pathItemComponents[$name] ?? null;
            if (! is_array($target)) {
                break; // unresolvable
            }

            $item = $target;
        }

        // Merge once (no array union inside the loop): a closer layer wins, so
        // reverse the list and let array_replace apply later (higher-precedence)
        // layers last.
        return array_replace(...array_reverse($layers));
    }

    /**
     * Canonical endpoint "path" for grant matching. Path operations keep their
     * raw key ("/orders"); webhook operations are namespaced (WEBHOOK_GRANT_PREFIX)
     * so they live in a disjoint address space and can never be matched by — or
     * leak through — a grant intended for an identically-keyed real path (B5).
     *
     * @param  'paths'|'webhooks'  $container
     */
    private function canonicalEndpointPath(string $container, string $key): string
    {
        return $container === 'webhooks' ? self::WEBHOOK_GRANT_PREFIX.$key : $key;
    }

    /**
     * A playground server URL must be a syntactically valid ABSOLUTE http(s)
     * URL. This rejects empty/whitespace, schemeless ("not-a-url") and unsafe
     * schemes ("javascript:…") before they reach the spec the browser renders.
     */
    private function isValidServerUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
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
