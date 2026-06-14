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
            // Redact: the upstream URL may carry userinfo/signed query params, and
            // the HTTP client's exception message embeds the full request URI —
            // never write those secrets to the logs.
            Log::error('OpenAPI upstream spec fetch failed', [
                'url' => $this->redactUrl($url),
                'exception' => $e::class,
                'message' => $this->redactMessage($e->getMessage()),
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

        // Always prune unreachable components from the filtered spec (security
        // invariant: never ship the definitions of ungranted operations/schemas,
        // and never leave a dangling $ref). The closure follows callbacks →
        // pathItems, so a pathItem reused through a granted operation's callback
        // (inline or via a components.callbacks ref) is correctly preserved.
        // pruneComponents also drops a now-vacuous root `security` (when no
        // surviving operation inherits it) using the reachability it computes.
        $spec = $this->pruneComponents($spec);

        // Drop Link Objects whose target operation was filtered out, so a granted
        // operation can't leak a hidden endpoint's operationId/path via a link.
        $spec = $this->pruneDanglingLinks($spec);

        return $spec;
    }

    /**
     * Removes response/component Link Objects whose `operationId`/`operationRef`
     * targets an operation that did not survive filtering (and Link `$ref`s to a
     * components.link that was itself dropped). Otherwise a user granted only the
     * source operation would receive a reference to — and the path/id of — an
     * endpoint they cannot see.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function pruneDanglingLinks(array $spec): array
    {
        // Surviving operation identity (this runs AFTER pruneComponents, so the
        // components present are already the surviving ones):
        //   $ids       – operationId names from ALL surviving operations (incl.
        //                callbacks and component path items), since operationId is
        //                resolved globally.
        //   $locations – container-aware "container\tmethod\tkey" for the TOP-LEVEL
        //                paths/webhooks operations an operationRef can target.
        $ids = [];
        $locations = [];
        $components = $this->asArray($spec['components'] ?? null);

        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $key => $pathItem) {
                foreach ($this->operations($pathItem) as $method => $operation) {
                    $locations[$container."\t".strtolower($method)."\t".(string) $key] = true;
                }
                $this->collectOperationIds($pathItem, $ids);
            }
        }
        foreach ($this->asArray($components['pathItems'] ?? null) as $name => $pathItem) {
            foreach ($this->operations($pathItem) as $method => $operation) {
                $locations['components.pathItems'."\t".strtolower($method)."\t".(string) $name] = true;
            }
            $this->collectOperationIds($pathItem, $ids);
        }
        foreach ($this->asArray($components['callbacks'] ?? null) as $callback) {
            foreach ($this->asArray($callback) as $pathItem) {
                $this->collectOperationIds($pathItem, $ids);
            }
        }

        // Which components.links survive (by their own target). A $ref to another
        // components.link is conservatively kept (chains are rare).
        $survivingLinkNames = [];
        foreach ($this->asArray($components['links'] ?? null) as $name => $link) {
            if ($this->linkTargetSurvives($link, $ids, $locations, [])) {
                $survivingLinkNames[(string) $name] = true;
            }
        }

        // Filter inline links recursively (operations → responses + callbacks).
        foreach (['paths', 'webhooks'] as $container) {
            $items = $this->asArray($spec[$container] ?? null);
            if ($items === []) {
                continue;
            }
            $rebuilt = [];
            foreach ($items as $key => $pathItem) {
                $rebuilt[$key] = $this->filterLinksInPathItem($pathItem, $ids, $locations, $survivingLinkNames);
            }
            $spec[$container] = $rebuilt;
        }

        if (! is_array($spec['components'] ?? null)) {
            return $spec;
        }
        $components = $spec['components'];

        if (is_array($components['pathItems'] ?? null)) {
            $pathItems = [];
            foreach ($components['pathItems'] as $name => $pathItem) {
                $pathItems[$name] = $this->filterLinksInPathItem($pathItem, $ids, $locations, $survivingLinkNames);
            }
            $components['pathItems'] = $pathItems;
        }
        if (is_array($components['callbacks'] ?? null)) {
            $callbacks = [];
            foreach ($components['callbacks'] as $name => $callback) {
                $callbacks[$name] = $this->filterLinksInCallback($callback, $ids, $locations, $survivingLinkNames);
            }
            $components['callbacks'] = $callbacks;
        }
        if (is_array($components['responses'] ?? null)) {
            $components['responses'] = $this->filterLinksInResponses($components['responses'], $ids, $locations, $survivingLinkNames);
        }
        if (is_array($components['links'] ?? null)) {
            $kept = [];
            foreach ($components['links'] as $name => $link) {
                if (isset($survivingLinkNames[(string) $name])) {
                    $kept[$name] = $link;
                }
            }
            $components['links'] = $kept;
        }

        $spec['components'] = $components;

        return $spec;
    }

    /**
     * Collects operationId names from a path item's operations, recursing through
     * their callbacks.
     *
     * @param  array<string, true>  $ids  (by-ref accumulator)
     */
    private function collectOperationIds(mixed $pathItem, array &$ids): void
    {
        foreach ($this->operations($pathItem) as $operation) {
            $operationId = $operation['operationId'] ?? null;
            if (is_string($operationId)) {
                $ids[$operationId] = true;
            }
            foreach ($this->asArray($operation['callbacks'] ?? null) as $callback) {
                foreach ($this->asArray($callback) as $cbPathItem) {
                    $this->collectOperationIds($cbPathItem, $ids);
                }
            }
        }
    }

    /**
     * Filters dangling Link Objects from a path item: its operations' responses
     * and (recursively) their callbacks. Non-arrays pass through unchanged.
     *
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $locations
     * @param  array<string, true>  $survivingLinkNames
     */
    private function filterLinksInPathItem(mixed $pathItem, array $ids, array $locations, array $survivingLinkNames): mixed
    {
        if (! is_array($pathItem)) {
            return $pathItem;
        }

        foreach (self::HTTP_VERBS as $verb) {
            if (! is_array($pathItem[$verb] ?? null)) {
                continue;
            }
            $operation = $pathItem[$verb];
            if (is_array($operation['responses'] ?? null)) {
                $operation['responses'] = $this->filterLinksInResponses($operation['responses'], $ids, $locations, $survivingLinkNames);
            }
            if (is_array($operation['callbacks'] ?? null)) {
                $callbacks = [];
                foreach ($operation['callbacks'] as $name => $callback) {
                    $callbacks[$name] = $this->filterLinksInCallback($callback, $ids, $locations, $survivingLinkNames);
                }
                $operation['callbacks'] = $callbacks;
            }
            $pathItem[$verb] = $operation;
        }

        return $pathItem;
    }

    /**
     * Filters dangling Link Objects within a Callback object (expr → path item).
     *
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $locations
     * @param  array<string, true>  $survivingLinkNames
     */
    private function filterLinksInCallback(mixed $callback, array $ids, array $locations, array $survivingLinkNames): mixed
    {
        if (! is_array($callback)) {
            return $callback;
        }

        foreach ($callback as $expression => $pathItem) {
            $callback[$expression] = $this->filterLinksInPathItem($pathItem, $ids, $locations, $survivingLinkNames);
        }

        return $callback;
    }

    /**
     * Drops dangling Link Objects from a Responses map.
     *
     * @param  array<array-key, mixed>  $responses
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $locations
     * @param  array<string, true>  $survivingLinkNames
     * @return array<array-key, mixed>
     */
    private function filterLinksInResponses(array $responses, array $ids, array $locations, array $survivingLinkNames): array
    {
        $result = [];
        foreach ($responses as $code => $response) {
            if (is_array($response) && is_array($response['links'] ?? null)) {
                $links = [];
                foreach ($response['links'] as $name => $link) {
                    if ($this->linkTargetSurvives($link, $ids, $locations, $survivingLinkNames)) {
                        $links[$name] = $link;
                    }
                }
                $response['links'] = $links;
            }
            $result[$code] = $response;
        }

        return $result;
    }

    /**
     * Whether a Link Object's target survives: a $ref to a surviving
     * components.link, an operationId in the surviving set, or an operationRef
     * resolving to a surviving path/webhook operation. A link with no resolvable
     * local target (external operationRef, or none) is kept.
     *
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $locations
     * @param  array<string, true>  $survivingLinkNames
     */
    private function linkTargetSurvives(mixed $link, array $ids, array $locations, array $survivingLinkNames): bool
    {
        if (! is_array($link)) {
            return true;
        }

        $ref = $link['$ref'] ?? null;
        if (is_string($ref)) {
            $prefix = '#/components/links/';
            if (str_starts_with($ref, $prefix)) {
                return isset($survivingLinkNames[substr($ref, strlen($prefix))]);
            }

            return true; // external/unknown link ref — leave as-is
        }

        $operationId = $link['operationId'] ?? null;
        if (is_string($operationId)) {
            return isset($ids[$operationId]);
        }

        $operationRef = $link['operationRef'] ?? null;
        if (is_string($operationRef)) {
            return $this->operationRefSurvives($operationRef, $locations);
        }

        return true;
    }

    /**
     * Whether an `operationRef` targets a surviving operation. Handles the LOCAL
     * forms — "#/paths/<escaped-path>/<method>", "#/webhooks/<key>/<method>" and
     * "#/components/pathItems/<name>/<method>" — checked against the location set.
     * Any OTHER local "#/components/…" ref (e.g. into a callback, which can't be
     * unambiguously resolved) is dropped conservatively; external/unknown refs
     * are left untouched.
     *
     * @param  array<string, true>  $locations
     */
    private function operationRefSurvives(string $operationRef, array $locations): bool
    {
        foreach (['paths', 'webhooks'] as $container) {
            $prefix = '#/'.$container.'/';
            if (! str_starts_with($operationRef, $prefix)) {
                continue;
            }

            $rest = substr($operationRef, strlen($prefix));
            $pos = strrpos($rest, '/'); // path/key may contain '/'; method is last
            if ($pos === false) {
                return true;
            }

            $key = str_replace(['~1', '~0'], ['/', '~'], substr($rest, 0, $pos));
            $method = strtolower(substr($rest, $pos + 1));

            return isset($locations[$container."\t".$method."\t".$key]);
        }

        $pathItemPrefix = '#/components/pathItems/';
        if (str_starts_with($operationRef, $pathItemPrefix)) {
            $rest = substr($operationRef, strlen($pathItemPrefix));
            $pos = strpos($rest, '/'); // a component name has no '/'
            if ($pos === false) {
                return false;
            }
            $name = str_replace(['~1', '~0'], ['/', '~'], substr($rest, 0, $pos));
            $method = strtolower(substr($rest, $pos + 1));
            if (str_contains($method, '/')) {
                return false; // pointer goes deeper than an operation — can't confirm
            }

            return isset($locations['components.pathItems'."\t".$method."\t".$name]);
        }

        if (str_starts_with($operationRef, '#/components/')) {
            return false; // other local component op ref we can't safely resolve
        }

        return true; // external/unknown operationRef — leave as-is
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
                // Redact: a rejected entry may itself carry credentials in its URL.
                Log::warning('Skipping invalid OpenAPI server entry (missing/empty/malformed url)', [
                    'url' => is_string($url) ? $this->redactUrl($url) : '[non-string]',
                ]);

                continue;
            }

            $entry = ['url' => trim($url)];
            $description = $server['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $entry['description'] = $description;
            }
            $valid[] = $entry;
        }

        // Nested `servers` (path-item / operation / callback level) override the
        // top-level set per the OpenAPI spec, so they must be stripped too —
        // otherwise surviving operations could still expose upstream/internal
        // URLs even after we replace or clear the top-level list.
        $spec = $this->stripNestedServers($spec);

        // Always override: if the admin activated no (valid) servers, REMOVE the
        // upstream `servers` so Scalar never shows the upstream URLs — only the
        // admin-approved set, which here is empty.
        if ($valid === []) {
            unset($spec['servers']);
        } else {
            $spec['servers'] = $valid;
        }

        return $spec;
    }

    /**
     * Removes `servers` from every path item and operation (and their nested
     * callback path items) in paths/webhooks/components, so only the top-level
     * admin-approved server list survives. Walks STRUCTURALLY (path-item →
     * operation → callbacks → path-item) so a schema property literally named
     * "servers" is never touched.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function stripNestedServers(array $spec): array
    {
        foreach (['paths', 'webhooks'] as $container) {
            $items = $this->asArray($spec[$container] ?? null);
            if ($items === []) {
                continue;
            }
            $stripped = [];
            foreach ($items as $key => $pathItem) {
                $stripped[$key] = $this->stripPathItemServers($pathItem);
            }
            $spec[$container] = $stripped;
        }

        $components = $spec['components'] ?? null;
        if (! is_array($components)) {
            return $spec;
        }

        if (is_array($components['pathItems'] ?? null)) {
            $pathItems = [];
            foreach ($components['pathItems'] as $key => $pathItem) {
                $pathItems[$key] = $this->stripPathItemServers($pathItem);
            }
            $components['pathItems'] = $pathItems;
        }

        if (is_array($components['callbacks'] ?? null)) {
            $callbacks = [];
            foreach ($components['callbacks'] as $name => $callback) {
                $callbacks[$name] = $this->stripCallbackServers($callback);
            }
            $components['callbacks'] = $callbacks;
        }

        if (is_array($components['links'] ?? null)) {
            $links = [];
            foreach ($components['links'] as $name => $link) {
                if (is_array($link)) {
                    unset($link['server']);
                }
                $links[$name] = $link;
            }
            $components['links'] = $links;
        }

        // Reusable responses ($ref'd by surviving operations) can also hold Link
        // Objects with a `server` — strip those too (same shape as inline responses).
        if (is_array($components['responses'] ?? null)) {
            $components['responses'] = $this->stripLinkServers($components['responses']);
        }

        $spec['components'] = $components;

        return $spec;
    }

    /**
     * Strips Server Objects from a path-item object and each of its operations:
     * the operation/path-item `servers` arrays, the singular `server` of any Link
     * Object under `responses.*.links.*`, and (recursing) operation callbacks.
     * Non-arrays pass through unchanged. Server Objects occur in exactly these
     * spec locations (root/path-item/operation servers + Link server), so this
     * makes server stripping exhaustive.
     */
    private function stripPathItemServers(mixed $pathItem): mixed
    {
        if (! is_array($pathItem)) {
            return $pathItem;
        }

        unset($pathItem['servers']);

        foreach (self::HTTP_VERBS as $verb) {
            if (! is_array($pathItem[$verb] ?? null)) {
                continue;
            }
            $operation = $pathItem[$verb];
            unset($operation['servers']);

            if (is_array($operation['callbacks'] ?? null)) {
                $callbacks = [];
                foreach ($operation['callbacks'] as $name => $callback) {
                    $callbacks[$name] = $this->stripCallbackServers($callback);
                }
                $operation['callbacks'] = $callbacks;
            }

            if (is_array($operation['responses'] ?? null)) {
                $operation['responses'] = $this->stripLinkServers($operation['responses']);
            }

            $pathItem[$verb] = $operation;
        }

        return $pathItem;
    }

    /**
     * Strips nested `servers` from a Callback object (expression → path item).
     */
    private function stripCallbackServers(mixed $callback): mixed
    {
        if (! is_array($callback)) {
            return $callback;
        }

        foreach ($callback as $expression => $pathItem) {
            $callback[$expression] = $this->stripPathItemServers($pathItem);
        }

        return $callback;
    }

    /**
     * Removes the singular `server` field from every Link Object in a Responses
     * map (responses.*.links.*.server), so a Link's Server URL can't expose an
     * upstream/internal host after the top-level servers are replaced.
     *
     * @param  array<array-key, mixed>  $responses
     * @return array<array-key, mixed>
     */
    private function stripLinkServers(array $responses): array
    {
        $result = [];
        foreach ($responses as $code => $response) {
            if (is_array($response) && is_array($response['links'] ?? null)) {
                $links = [];
                foreach ($response['links'] as $name => $link) {
                    if (is_array($link)) {
                        unset($link['server']);
                    }
                    $links[$name] = $link;
                }
                $response['links'] = $links;
            }
            $result[$code] = $response;
        }

        return $result;
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
            // No components to prune, but a vacuous root `security` (no inline
            // operation inherits it) is still dropped.
            if (isset($spec['security']) && ! $this->inheritsRootSecurity($spec, [])) {
                unset($spec['security']);
            }

            return $spec;
        }

        // Seed reachability from $refs and operation-level security under paths
        // AND webhooks (path-item-level $refs covered — collectReachableRefs walks
        // the subtree). Root `security` schemes are intentionally NOT seeded yet:
        // whether root security survives depends on the reachable set computed
        // below, so seeding it up front could keep schemes no visible op uses.
        $reachable = [];                                   // "schemas/Foo" => true
        $this->drainReachability([
            ...$this->collectReachableRefs($spec['paths'] ?? []),
            ...$this->collectReachableRefs($spec['webhooks'] ?? []),
        ], $reachable, $components);

        // Decide root `security` now that the reachable set is known: keep it only
        // if a surviving operation inherits it (inline in paths/webhooks, or in a
        // REACHABLE components.pathItems/callbacks). If kept, drain its schemes too
        // (a scheme may itself be a Reference Object); otherwise drop the vacuous
        // requirement.
        if (isset($spec['security'])) {
            if ($this->inheritsRootSecurity($spec, $reachable)) {
                $rootQueue = [];
                foreach ($this->securityRequirementSchemes($spec['security']) as $schemeName) {
                    $rootQueue[] = 'securitySchemes/'.$schemeName;
                }
                $this->drainReachability($rootQueue, $reachable, $components);
            } else {
                unset($spec['security']);
            }
        }

        // Rebuild components keeping only reachable entries. A non-array member
        // (e.g. an `x-*` extension scalar) is never reachable via $ref, so it is
        // dropped from the filtered spec rather than copied through.
        $pruned = [];
        foreach ($components as $type => $entries) {
            if (! is_array($entries)) {
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
     * Marks every component transitively reachable from $queue into $reachable
     * (keyed "type/name"), following each reachable component's child refs.
     *
     * An Example component is special-cased: its `value` payload is data (never
     * traversed), but if the component is itself a Reference Object its `$ref` is
     * still followed (so an example alias keeps its target alive).
     *
     * @param  list<string>  $queue
     * @param  array<string, true>  $reachable  (by-ref accumulator)
     * @param  array<array-key, mixed>  $components
     */
    private function drainReachability(array $queue, array &$reachable, array $components): void
    {
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

            // Example component: follow only a top-level $ref (Reference Object),
            // never its `value` data. Other components are walked in full.
            $children = $type === 'examples'
                ? $this->collectReachableRefs(['$ref' => (is_array($component) ? ($component['$ref'] ?? null) : null)])
                : $this->collectReachableRefs($component);

            foreach ($children as $child) {
                if (! isset($reachable[$child])) {
                    $queue[] = $child;
                }
            }
        }
    }

    /**
     * Collects all component reachability keys ("type/name") present in a node —
     * both `$ref` component references and `security`-named securitySchemes —
     * in one context-aware walk so the same guards apply to both.
     *
     * - A `$ref` is normalised to its OWNING component: a JSON Pointer into a
     *   component (e.g. "#/components/schemas/Pet/properties/id") yields
     *   "schemas/Pet", so the owning component isn't pruned (which would dangle).
     * - A `security` keyword yields "securitySchemes/<name>" per requirement.
     * - EXAMPLE/PAYLOAD DATA is skipped: a literal `"$ref"` or `"security"` key
     *   inside an `example`/`examples` value is user data, not a real reference,
     *   so it must not keep an otherwise-unreachable component/scheme alive.
     * - The keyword guards apply ONLY in keyword positions: a schema PROPERTY
     *   named "example"/"security"/… (under `properties`/`$defs`/… where keys are
     *   arbitrary names) is still walked, so its real `$ref` is honoured.
     *
     * @return list<string>
     */
    private function collectReachableRefs(mixed $node): array
    {
        $refs = [];
        $prefix = '#/components/';

        // JSON Schema maps whose keys are arbitrary NAMES (so a key like
        // "example"/"security" there is a property name, not a keyword).
        $nameMaps = ['properties', '$defs', 'definitions', 'patternProperties', 'dependentSchemas'];

        $addComponent = static function (mixed $ref) use (&$refs, $prefix): void {
            if (is_string($ref) && str_starts_with($ref, $prefix)) {
                $segments = explode('/', substr($ref, strlen($prefix)));
                if (count($segments) >= 2) {
                    $refs[] = $segments[0].'/'.$segments[1]; // owning "type/name"
                }
            }
        };

        $walk = function (mixed $value, bool $keysAreNames) use (&$walk, &$refs, &$addComponent, $nameMaps, $prefix): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (! $keysAreNames) {
                    if ($key === '$ref') {
                        $addComponent($child);

                        continue;
                    }

                    // `security` keyword: a Security Requirement array referencing
                    // schemes by name (leaf data — no nested refs to recurse).
                    if ($key === 'security') {
                        foreach ($this->securityRequirementSchemes($child) as $name) {
                            $refs[] = 'securitySchemes/'.$name;
                        }

                        continue;
                    }

                    // `discriminator.mapping` values are schema references by URI
                    // or bare name (NOT under a $ref key), so collect them or the
                    // polymorphic target schemas get pruned.
                    if ($key === 'discriminator') {
                        $mapping = is_array($child) ? ($child['mapping'] ?? null) : null;
                        foreach ($this->asArray($mapping) as $target) {
                            if (! is_string($target)) {
                                continue;
                            }
                            if (str_starts_with($target, $prefix)) {
                                $addComponent($target);            // explicit component ref
                            } elseif (! str_contains($target, '/') && ! str_contains($target, '#')) {
                                $refs[] = 'schemas/'.$target;      // bare name => schema
                            }
                        }

                        continue;
                    }

                    // `example` (singular) keyword is free-form data.
                    if ($key === 'example') {
                        continue;
                    }

                    // `examples` keyword: a JSON-Schema array of raw values
                    // (data) or a map of Example Objects. In the map case an
                    // entry may be a real {$ref: #/components/examples/X};
                    // collect that but never recurse into an example's `value`.
                    if ($key === 'examples' && is_array($child)) {
                        if (! array_is_list($child)) {
                            foreach ($child as $example) {
                                if (is_array($example)) {
                                    $addComponent($example['$ref'] ?? null);
                                }
                            }
                        }

                        continue;
                    }
                }

                // Entering a name-map: its children's keys are names, so keyword
                // handling must NOT apply at that level.
                $childKeysAreNames = ! $keysAreNames && in_array((string) $key, $nameMaps, true);
                $walk($child, $childKeysAreNames);
            }
        };

        $walk($node, false);

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
                // A digit-only scheme name decodes to an int array key, so cast
                // rather than is_string-guard (else its scheme dangles on prune).
                $names[] = (string) $schemeName;
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
     * Whether at least one SURVIVING operation inherits the root `security` —
     * i.e. lacks its own `security` key. An operation that declares `security`
     * (including `security: []`, an explicit opt-out) does not inherit root; one
     * without the key does.
     *
     * Counts operations inline in paths/webhooks (and their inline callbacks) and
     * those in components.pathItems/callbacks that are REACHABLE (per $reachable
     * keyed "type/name"), since a callback operation without `security` inherits
     * the API-wide requirement. Unreachable components are excluded so they can't
     * keep root security — and its securityScheme metadata — alive for endpoints
     * the user can't see.
     *
     * @param  array<array-key, mixed>  $spec
     * @param  array<string, true>  $reachable
     */
    private function inheritsRootSecurity(array $spec, array $reachable): bool
    {
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                if ($this->pathItemInheritsRootSecurity($pathItem)) {
                    return true;
                }
            }
        }

        $components = $this->asArray($spec['components'] ?? null);
        foreach ($this->asArray($components['pathItems'] ?? null) as $name => $pathItem) {
            if (isset($reachable['pathItems/'.$name]) && $this->pathItemInheritsRootSecurity($pathItem)) {
                return true;
            }
        }
        foreach ($this->asArray($components['callbacks'] ?? null) as $name => $callback) {
            if (! isset($reachable['callbacks/'.$name])) {
                continue;
            }
            foreach ($this->asArray($callback) as $pathItem) {
                if ($this->pathItemInheritsRootSecurity($pathItem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a path item has any operation that inherits root `security` (no
     * own `security` key), recursing through that operation's callbacks.
     */
    private function pathItemInheritsRootSecurity(mixed $pathItem): bool
    {
        foreach ($this->operations($pathItem) as $operation) {
            if (! array_key_exists('security', $operation)) {
                return true;
            }
            foreach ($this->asArray($operation['callbacks'] ?? null) as $callback) {
                foreach ($this->asArray($callback) as $cbPathItem) {
                    if ($this->pathItemInheritsRootSecurity($cbPathItem)) {
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
            // Don't echo the raw URL — it may carry userinfo/signed query secrets.
            throw new InvalidOpenApiSpecException('OpenAPI upstream URL is invalid (missing scheme or host).');
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
     * Safe-for-logs form of a URL: scheme://host[:port][path] only — drops
     * userinfo, query and fragment (which can carry credentials / signed tokens).
     */
    private function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';

        return strtolower((string) $parts['scheme']).'://'.$parts['host'].$port.$path;
    }

    /**
     * Strips userinfo and query/fragment from any http(s) URL embedded in a
     * string (e.g. an HTTP client's exception message), so secrets in the
     * upstream URI never reach the logs.
     */
    private function redactMessage(string $message): string
    {
        // Userinfo group is greedy up to the LAST '@' before the path delimiter,
        // so a password that itself contains '@' (e.g. user:p@ss@host, which PHP
        // accepts) is fully stripped, not just up to the first '@'.
        return (string) preg_replace(
            '~(https?://)(?:[^/\s?#]*@)?([^/\s?#]+)([^\s?#]*)[^\s]*~i',
            '$1$2$3',
            $message
        );
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
     * URL. This rejects empty/whitespace, schemeless ("not-a-url"), unsafe
     * schemes ("javascript:…") and CREDENTIAL-bearing URLs
     * ("https://user:pass@host", which would ship credentials to every user's
     * browser) before they reach the spec the browser renders.
     */
    private function isValidServerUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false; // never expose embedded credentials client-side
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
