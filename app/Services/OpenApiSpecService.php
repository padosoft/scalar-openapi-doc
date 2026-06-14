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

    /**
     * Internal reachability marker for a JSON Schema anchor fragment ref
     * ("#name"): resolved against $anchor/$dynamicAnchor/$recursiveAnchor
     * declarations. The NUL bytes keep it from ever colliding with a real
     * "type/name" component key.
     */
    private const ANCHOR_REF_PREFIX = "\0anchorRef\0";

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

        // Re-prune: a dropped link may have been the only reference keeping some
        // component (e.g. a schema under components.links.X) reachable, so a second
        // pass removes anything now orphaned by the link removal.
        $spec = $this->pruneComponents($spec);

        // `paths` is an OpenAPI MAP and must serialize as a JSON object. When a user
        // has no surviving path operations (e.g. webhook-only or no grants) the
        // filtered map is empty; left as a PHP [] it would json_encode to "[]",
        // producing an invalid document validators/Scalar reject. Force the object
        // form. (Done last, so the array-based pruning above is unaffected.)
        if (($spec['paths'] ?? null) === []) {
            $spec['paths'] = (object) [];
        }

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

        // Which components.links survive. First mark the ones with a DIRECT target
        // (operationId/operationRef, or no target), then resolve alias links
        // ($ref to another components.link) to a fixpoint so a surviving chain
        // Alias -> Real is kept while an alias to a dropped link is removed.
        $componentLinks = $this->asArray($components['links'] ?? null);
        $survivingLinkNames = [];
        foreach ($componentLinks as $name => $link) {
            if (is_array($link) && isset($link['$ref'])) {
                continue; // alias — decided in the fixpoint below
            }
            if ($this->linkTargetSurvives($link, $ids, $locations, [])) {
                $survivingLinkNames[(string) $name] = true;
            }
        }
        $linkFragmentPrefix = '/components/links/';
        do {
            $changed = false;
            foreach ($componentLinks as $name => $link) {
                $key = (string) $name;
                if (isset($survivingLinkNames[$key]) || ! is_array($link)) {
                    continue;
                }
                $ref = $link['$ref'] ?? null;
                if (! is_string($ref)) {
                    continue;
                }
                // A same-document alias survives once its target link is known to
                // survive AND its own sibling operationId/operationRef (if any)
                // also survive — a malformed alias must not leak via a sibling.
                // A same-document $ref to a NON-links component (e.g. a schema) is
                // malformed: never mark it surviving. Only a truly external/unknown
                // $ref is kept conservatively.
                $fragment = $this->localFragment($ref);
                if ($fragment !== null) {
                    if (! str_starts_with($fragment, $linkFragmentPrefix)) {
                        continue; // local ref to a non-link component — drop
                    }
                    $aliasTargetSurvives = isset(
                        $survivingLinkNames[substr($fragment, strlen($linkFragmentPrefix))]
                    );
                } else {
                    $aliasTargetSurvives = true; // external/unknown — conservative keep
                }
                if ($aliasTargetSurvives && $this->linkSiblingTargetsSurvive($link, $ids, $locations)) {
                    $survivingLinkNames[$key] = true;
                    $changed = true;
                }
            }
        } while ($changed);

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

        // A link survives only if EVERY local target field present resolves to a
        // surviving target. A malformed upstream link may combine several (a
        // $ref to a surviving components.link AND a sibling operationId/
        // operationRef pointing at a filtered, hidden op) — checking only one and
        // returning early would leak the hidden path, so check ALL present.
        $ref = $link['$ref'] ?? null;
        if (is_string($ref)) {
            $fragmentPrefix = '/components/links/';
            $fragment = $this->localFragment($ref);
            if ($fragment !== null) {
                // A Link's same-document $ref MUST point to components.links; any
                // other local component ref is malformed — drop the link (don't
                // let it keep, or dangle at, a non-link component like a schema).
                if (! str_starts_with($fragment, $fragmentPrefix)
                    || ! isset($survivingLinkNames[substr($fragment, strlen($fragmentPrefix))])
                ) {
                    return false;
                }
            }
            // external/unknown $ref: not a local leak — fall through to siblings.
        }

        return $this->linkSiblingTargetsSurvive($link, $ids, $locations);
    }

    /**
     * Whether a Link Object's operationId/operationRef sibling target fields (if
     * present) resolve to a surviving operation. Shared by inline-link filtering
     * and the components.links alias fixpoint so a malformed link can't leak a
     * hidden operation id/path via a sibling.
     *
     * @param  array<array-key, mixed>  $link
     * @param  array<string, true>  $ids
     * @param  array<string, true>  $locations
     */
    private function linkSiblingTargetsSurvive(array $link, array $ids, array $locations): bool
    {
        $operationId = $link['operationId'] ?? null;
        if (is_string($operationId) && ! isset($ids[$operationId])) {
            return false;
        }

        $operationRef = $link['operationRef'] ?? null;
        if (is_string($operationRef) && ! $this->operationRefSurvives($operationRef, $locations)) {
            return false;
        }

        return true;
    }

    /**
     * The same-document JSON-pointer fragment of a ref, or null if the ref is
     * external or has no fragment.
     *
     * A pure fragment ("#/…") is always same-document. A BASED ref ("./file#/…",
     * "https://host/doc#/…") is same-document only when its base resolves to the
     * configured upstream document — a sibling file ("./common.yaml#/…") or a
     * different host targets ANOTHER document, so its local-looking fragment must
     * NOT keep/resolve our local components.
     *
     * The fragment is PERCENT-DECODED (RFC 3986 fragments are percent-encoded), so
     * a pointer written as "#%2Fpaths%2F~1admin%2Fget" is recognised as the local
     * "/paths/~1admin/get" and not mistaken for an external ref. JSON Pointer "~0"/
     * "~1" escapes are NOT percent-encoding and stay intact for callers to unescape.
     */
    private function localFragment(string $ref): ?string
    {
        $hash = strpos($ref, '#');
        if ($hash === false) {
            return null;
        }

        $base = substr($ref, 0, $hash);
        if ($base === '') {
            return rawurldecode(substr($ref, $hash + 1)); // pure fragment — same document
        }

        return $this->refBaseIsCurrentDocument($base) ? rawurldecode(substr($ref, $hash + 1)) : null;
    }

    /**
     * Whether a ref's base (the part before '#') resolves to the configured
     * upstream document. The base is resolved as a URI-reference against the
     * upstream URL and the NORMALIZED full document URIs are compared — so a
     * same-name sibling in a different directory ("../common/openapi.json" vs an
     * upstream ".../v1/openapi.json") is correctly treated as a different document.
     */
    private function refBaseIsCurrentDocument(string $base): bool
    {
        $upstream = config('openapi.upstream_url');
        if (! is_string($upstream) || $upstream === '') {
            return false;
        }

        // Compare INCLUDING the query: a query distinguishes representations, so a
        // ref carrying a different (or any) query targets a different document and
        // must not be treated as same-document. Only the fragment is dropped.
        $upstreamTarget = $this->normalizeAbsolute($this->stripFragment($upstream));
        if ($upstreamTarget === null) {
            return false;
        }
        $resolved = $this->resolveUriReference($base, $upstreamTarget);

        return $resolved !== null && $resolved === $upstreamTarget;
    }

    /**
     * The owning "type/name" of a same-document component ref, or null if the ref
     * is external or not a component pointer. No operation-bearing guard — callers
     * in structural (path-item/callback) positions use this directly.
     */
    private function owningComponentRef(string $ref): ?string
    {
        $fragment = $this->localFragment($ref);
        $localPrefix = '/components/';
        if ($fragment === null || ! str_starts_with($fragment, $localPrefix)) {
            return null;
        }
        $segments = explode('/', substr($fragment, strlen($localPrefix)));

        return count($segments) >= 2 ? $segments[0].'/'.$segments[1] : null;
    }

    private function stripFragment(string $url): string
    {
        return explode('#', $url)[0]; // keep query, drop fragment
    }

    /**
     * Minimal RFC-3986-style resolution of a URI-reference (which may carry a
     * query) against a base URL, returning the normalised absolute target
     * INCLUDING its query (or null if unresolvable). The fragment is assumed
     * already removed by the caller.
     */
    private function resolveUriReference(string $ref, string $baseUrl): ?string
    {
        if ($ref === '') {
            return $baseUrl;
        }
        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $ref) === 1) {
            return $this->normalizeAbsolute($ref); // absolute URI — normalise path
        }

        $baseParts = parse_url($baseUrl);
        if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $scheme = (string) $baseParts['scheme'];
        if (str_starts_with($ref, '//')) {
            return $this->normalizeAbsolute($scheme.':'.$ref); // protocol-relative
        }

        $origin = $scheme.'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
        $basePath = is_string($baseParts['path'] ?? null) ? $baseParts['path'] : '/';

        $q = strpos($ref, '?');
        $refPath = $q === false ? $ref : substr($ref, 0, $q);
        $refQuery = $q === false ? '' : substr($ref, $q); // includes leading '?'

        if ($refPath === '') {
            // Same path, different query (e.g. "?variant=x").
            $path = $basePath;
            $query = $refQuery !== '' ? $refQuery : (isset($baseParts['query']) ? '?'.$baseParts['query'] : '');
        } elseif (str_starts_with($refPath, '/')) {
            $path = $this->normalizePath($refPath);
            $query = $refQuery;
        } else {
            $slash = strrpos($basePath, '/');
            $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $path = $this->normalizePath($dir.$refPath);
            $query = $refQuery;
        }

        return $origin.$path.$query;
    }

    /**
     * Normalises an absolute URL: collapses "." / ".." in the path and keeps the
     * query, so e.g. "https://h/v1/../openapi.json" == "https://h/openapi.json".
     * Returns null if not an absolute URL with scheme + host.
     */
    private function normalizeAbsolute(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // Scheme and host are case-insensitive; the default port is equivalent to
        // none — normalise so "HTTPS://Host:443/x" == "https://host/x".
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portStr = ($port !== null && ! $isDefaultPort) ? ':'.$port : '';

        $origin = $scheme.'://'.$host.$portStr;
        $path = $this->normalizePath(is_string($parts['path'] ?? null) ? $parts['path'] : '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $origin.$path.$query;
    }

    /**
     * Normalises a URL path, resolving "." and ".." segments per RFC 3986 §5.2.4
     * WITHOUT collapsing empty segments: "//openapi.json" and "/openapi.json/" are
     * distinct paths from "/openapi.json" and must stay distinct, or an external
     * ref like "https://host//openapi.json#/..." would compare equal to the
     * configured upstream document and leak its local components.
     */
    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $leadingSlash = str_starts_with($path, '/');
        $segments = explode('/', $path);
        if ($leadingSlash) {
            array_shift($segments); // drop the empty segment before the leading '/'
        }
        $lastIndex = count($segments) - 1;
        $out = [];
        foreach ($segments as $i => $segment) {
            $isLast = $i === $lastIndex;
            if ($segment === '.') {
                if ($isLast) {
                    $out[] = ''; // terminal "." leaves a trailing slash (RFC 3986 §5.2.4)
                }

                continue; // otherwise the current-directory marker is dropped
            }
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out); // ascend (pops a name OR an empty segment)
                } elseif (! $leadingSlash) {
                    $out[] = '..'; // relative path may keep a leading ".."
                }
                if ($isLast) {
                    $out[] = ''; // terminal ".." also leaves a trailing slash
                }

                continue;
            }
            $out[] = $segment; // keep names AND empty segments ("//", trailing "/")
        }

        return ($leadingSlash ? '/' : '').implode('/', $out);
    }

    /**
     * Whether an `operationRef` targets a surviving operation. Resolution is done
     * on the same-document FRAGMENT, so a relative ref
     * (e.g. "./openapi.json#/paths/~1admin/get") is matched the same as the bare
     * "#/paths/…" form — preventing a hidden path/key from leaking through a
     * filename-prefixed ref. A same-document fragment that points at a local
     * operation but does not resolve to a surviving one is dropped; an external
     * ref, or a fragment that isn't a recognizable local operation pointer, is
     * treated as external (kept).
     *
     * @param  array<string, true>  $locations
     */
    private function operationRefSurvives(string $operationRef, array $locations): bool
    {
        $fragment = $this->localFragment($operationRef);
        if ($fragment === null) {
            return true; // external/fragment-less — not a local-operation leak
        }

        foreach (['paths', 'webhooks'] as $container) {
            $prefix = '/'.$container.'/';
            if (! str_starts_with($fragment, $prefix)) {
                continue;
            }

            $rest = substr($fragment, strlen($prefix));
            $pos = strrpos($rest, '/'); // path/key may contain '/'; method is last
            if ($pos === false) {
                return false; // malformed local ref (no method) — drop conservatively
            }

            $key = str_replace(['~1', '~0'], ['/', '~'], substr($rest, 0, $pos));
            $method = strtolower(substr($rest, $pos + 1));

            return isset($locations[$container."\t".$method."\t".$key]);
        }

        if (str_starts_with($fragment, '/components/pathItems/')) {
            $rest = substr($fragment, strlen('/components/pathItems/'));
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

        if (str_starts_with($fragment, '/components/')) {
            return false; // other local component op ref we can't safely resolve
        }

        return true; // fragment isn't a recognizable local operation pointer
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
        // Map JSON Schema anchor names ($anchor/$dynamicAnchor/$recursiveAnchor)
        // to the components that declare them, so an anchor fragment ref ("#name")
        // keeps the anchored component reachable instead of dangling.
        $anchorOwners = [];
        foreach ($components as $type => $entries) {
            // Skip component types that either hold no schema anchors
            // (Example/Link/SecurityScheme) or are OPERATION-bearing
            // (pathItems/callbacks) — promoting a whole path item/callback just
            // because a nested schema declares an anchor would expose its
            // ungranted operations (P1). Anchors are resolved via schema-only
            // component types (schemas/responses/parameters/headers/requestBodies).
            if (! is_array($entries)
                || in_array((string) $type, ['examples', 'links', 'securitySchemes', 'pathItems', 'callbacks'], true)
            ) {
                continue;
            }
            foreach ($entries as $name => $entry) {
                foreach ($this->collectAnchorNames($entry) as $anchor) {
                    $anchorOwners[$anchor][] = ((string) $type).'/'.((string) $name);
                }
            }
        }

        // Seed from each Path Item under paths/webhooks. We iterate the map in PHP
        // (the keys are path/webhook NAMES, not keywords) and walk each VALUE as a
        // path-item position ($pathItemContext=true): so a path-item-level $ref may
        // reuse a pathItems component, and — crucially — the path item's HTTP-verb
        // children are recognised as Operation Objects whose `security` is a real
        // requirement (a non-operation object named "get" elsewhere is not).
        $seedRefs = [];
        foreach (['paths', 'webhooks'] as $container) {
            foreach ($this->asArray($spec[$container] ?? null) as $pathItem) {
                foreach ($this->collectReachableRefs($pathItem, false, true) as $ref) {
                    $seedRefs[] = $ref;
                }
            }
        }
        $reachable = [];                                   // "schemas/Foo" => true
        $this->drainReachability($seedRefs, $reachable, $components, $anchorOwners);

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
                $this->drainReachability($rootQueue, $reachable, $components, $anchorOwners);
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
     * still followed (so an example alias keeps its target alive). An anchor
     * fragment ref marker resolves to every component declaring that anchor.
     *
     * @param  list<string>  $queue
     * @param  array<string, true>  $reachable  (by-ref accumulator)
     * @param  array<array-key, mixed>  $components
     * @param  array<string, list<string>>  $anchorOwners  anchor name => owning "type/name"s
     */
    private function drainReachability(array $queue, array &$reachable, array $components, array $anchorOwners = []): void
    {
        while ($queue !== []) {
            $ref = array_pop($queue);
            if (isset($reachable[$ref])) {
                continue;
            }
            $reachable[$ref] = true;

            // Anchor fragment ref ("#name"): enqueue every component declaring it.
            if (str_starts_with($ref, self::ANCHOR_REF_PREFIX)) {
                $anchor = substr($ref, strlen(self::ANCHOR_REF_PREFIX));
                foreach ($anchorOwners[$anchor] ?? [] as $owner) {
                    if (! isset($reachable[$owner])) {
                        $queue[] = $owner;
                    }
                }

                continue;
            }

            [$type, $name] = array_pad(explode('/', $ref, 2), 2, null);
            if (! is_string($type) || ! is_string($name)) {
                continue;
            }
            $bucket = $components[$type] ?? null;
            $component = is_array($bucket) ? ($bucket[$name] ?? null) : null;
            if ($component === null) {
                continue;
            }

            // A top-level $ref on any of these structural components is an alias to
            // another component of the same (operation-bearing or not) type.
            $aliasRef = is_array($component) && is_string($component['$ref'] ?? null) ? $component['$ref'] : null;

            if (in_array($type, ['examples', 'links'], true)) {
                // Example/Link: follow only an alias $ref to a SAME-TYPE component;
                // other fields are data and a $ref to a different component type
                // (e.g. a schema) is malformed — never followed, so it can't keep
                // a hidden component alive.
                $owning = $aliasRef !== null ? $this->owningComponentRef($aliasRef) : null;
                $children = ($owning !== null && str_starts_with($owning, $type.'/')) ? [$owning] : [];
            } elseif ($type === 'callbacks') {
                if ($aliasRef !== null) {
                    $owning = $this->owningComponentRef($aliasRef); // callback alias
                    $children = ($owning !== null && str_starts_with($owning, 'callbacks/')) ? [$owning] : [];
                } else {
                    // Callback Object: a map of runtime expressions => path items.
                    $children = [];
                    foreach ($this->asArray($component) as $pathItem) {
                        $children = [...$children, ...$this->collectReachableRefs($pathItem, false, true)];
                    }
                }
            } elseif ($type === 'pathItems') {
                // A reusable path item — a path-item position, so its top-level
                // $ref (alias) may target another path item.
                $children = $this->collectReachableRefs($component, false, true);
            } else {
                // A `schemas` component is a Schema Object — walk it in schema
                // context so its JSON Schema `examples` data array isn't mistaken
                // for an OpenAPI Example Objects map (which would leak a schema a
                // literal example datum happens to "$ref").
                $children = $this->collectReachableRefs($component, false, false, $type === 'schemas');
            }

            foreach ($children as $child) {
                if (! isset($reachable[$child])) {
                    $queue[] = $child;
                }
            }
        }
    }

    /**
     * Collects the JSON Schema anchor names ($anchor / $dynamicAnchor /
     * $recursiveAnchor) declared within a node, using the SAME guards as ref
     * collection (so data payloads are skipped but a schema property literally
     * named "links"/"default" is still walked for real anchors).
     *
     * @return list<string>
     */
    private function collectAnchorNames(mixed $node): array
    {
        return $this->walkReachability($node)['anchors'];
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
     * Pass $keysAreNames=true when $node is itself a NAME map (e.g. the top-level
     * paths/webhooks maps, whose keys are arbitrary path/webhook names).
     *
     * Returns BOTH the reachability refs and the anchor declarations
     * ($anchor/$dynamicAnchor/$recursiveAnchor) found, so anchor collection shares
     * the exact same keyword/name-map/data-skip guards as ref collection.
     *
     * @return array{refs: list<string>, anchors: list<string>}
     */
    private function walkReachability(mixed $node, bool $keysAreNames = false, bool $pathItemContext = false, bool $inSchema = false, bool $securityIsRequirement = false): array
    {
        $refs = [];
        $anchors = [];

        // JSON Schema applicator keywords whose value(s) are subschemas. Descending
        // into any of them (or into the `schema` keyword from an OpenAPI object)
        // puts us — and everything below — in SCHEMA context, where `examples` is a
        // raw-data array (JSON Schema keyword), NOT an OpenAPI Example Objects map.
        $schemaSubMaps = ['properties', '$defs', 'definitions', 'patternProperties', 'dependentSchemas'];
        $schemaSubKeys = [
            'items', 'prefixItems', 'additionalProperties', 'additionalItems',
            'unevaluatedItems', 'unevaluatedProperties', 'contains', 'propertyNames',
            'contentSchema', 'not', 'if', 'then', 'else', 'allOf', 'anyOf', 'oneOf',
        ];

        // Maps whose keys are arbitrary NAMES (so a key like "example"/"security"
        // there is a name, not a keyword): JSON Schema schema-maps plus the
        // OpenAPI keyed maps (headers/content/encoding/links/callbacks/server
        // variables). callbacks nests one more name level (callbackName →
        // expression → path item), which the one-level flag handles since the
        // path item is then walked in keyword position.
        $nameMaps = [
            'properties', '$defs', 'definitions', 'patternProperties', 'dependentSchemas',
            'headers', 'content', 'encoding', 'variables', 'responses', 'scopes',
        ];

        // Resolves a SAME-DOCUMENT ref (pure "#/..." or a relative "./file#/...")
        // to its owning "type/name". An external URI ref (with a scheme/authority,
        // e.g. "https://other#/components/schemas/X") is ignored — it targets
        // another document, so our local component must not be kept alive by it.
        $addComponent = function (mixed $ref, ?string $allowOperationBearing = null) use (&$refs): void {
            if (! is_string($ref)) {
                return;
            }
            $owning = $this->owningComponentRef($ref);
            if ($owning === null) {
                return;
            }
            // Operation-bearing components (pathItems/callbacks) are reachable ONLY
            // from structural positions, and each position admits a SINGLE type: a
            // Path Item `$ref` resolves only to another Path Item, a Callback `$ref`
            // only to a Callback. `$allowOperationBearing` names the one type that
            // is legal here (null = none). A generic $ref from a schema/parameter/
            // response position — or a path-item $ref to a callback (or vice versa)
            // — must NOT keep an unfiltered path item/callback (with ungranted
            // operations) reachable in the filtered spec.
            $type = explode('/', $owning, 2)[0];
            if (in_array($type, ['pathItems', 'callbacks'], true) && $type !== $allowOperationBearing) {
                return;
            }
            $refs[] = $owning;
        };

        $walk = function (mixed $value, bool $keysAreNames, bool $pathItemContext, bool $inSchema, bool $securityIsRequirement) use (&$walk, &$refs, &$anchors, &$addComponent, $nameMaps, $schemaSubMaps, $schemaSubKeys): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (! $keysAreNames) {
                    // Specification extensions (x-*) are arbitrary vendor data —
                    // a `$ref`/`security` inside them is not a real reference, so
                    // skip the whole subtree (like example/default data).
                    if (is_string($key) && str_starts_with($key, 'x-')) {
                        continue;
                    }

                    // `links`/`callbacks` are OpenAPI Response/Operation fields and
                    // are NOT valid inside a Schema Object. A schema member named
                    // "links"/"callbacks" is non-standard data — skip it so it can't
                    // keep a link/callback (operation-bearing) component alive.
                    if ($inSchema && ($key === 'links' || $key === 'callbacks')) {
                        continue;
                    }

                    // JSON Schema anchor declarations (resolved by anchor-fragment
                    // refs). Collected here so anchor scanning shares these guards.
                    if (($key === '$anchor' || $key === '$dynamicAnchor' || $key === '$recursiveAnchor')
                        && is_string($child) && $child !== ''
                    ) {
                        $anchors[] = $child;

                        continue;
                    }

                    // $ref plus JSON Schema 2020-12 / draft-2019 dynamic refs.
                    if ($key === '$ref' || $key === '$dynamicRef' || $key === '$recursiveRef') {
                        if (is_string($child) && str_starts_with($child, '#') && ! str_contains($child, '/') && $child !== '#') {
                            // Anchor fragment ("#name") — resolved via anchor decls.
                            $refs[] = self::ANCHOR_REF_PREFIX.substr($child, 1);
                        } else {
                            // $pathItemContext: a path-item top-level $ref may reuse
                            // another Path Item ONLY (never a Callback component).
                            $addComponent($child, $pathItemContext ? 'pathItems' : null);
                        }

                        continue;
                    }

                    // `security` keyword: an OpenAPI Security Requirement array
                    // referencing schemes by name (leaf data — no nested refs). It
                    // is a requirement ONLY as a direct child of an Operation Object
                    // ($securityIsRequirement; root security is seeded separately).
                    // A `security` member anywhere else (a response/header/parameter/
                    // link, or inside a schema) is non-standard data and must NOT
                    // mark (leak) a securityScheme — so we skip it as opaque data.
                    if ($key === 'security') {
                        if ($securityIsRequirement) {
                            foreach ($this->securityRequirementSchemes($child) as $name) {
                                $refs[] = 'securitySchemes/'.$name;
                            }
                        }

                        continue;
                    }

                    // `links` map: a Link Object's only component reference is its
                    // own `$ref`, which must point to components.links — a $ref to
                    // any other component is malformed and dropped (it must not
                    // keep e.g. a hidden schema alive). parameters/requestBody are
                    // literal expression/Any DATA — never walked.
                    if ($key === 'links' && is_array($child)) {
                        foreach ($child as $link) {
                            $linkRef = is_array($link) && is_string($link['$ref'] ?? null) ? $link['$ref'] : null;
                            $owning = $linkRef !== null ? $this->owningComponentRef($linkRef) : null;
                            if ($owning !== null && str_starts_with($owning, 'links/')) {
                                $refs[] = $owning;
                            }
                        }

                        continue;
                    }

                    // `callbacks` map: callbackName => Callback ($ref to
                    // components.callbacks, OR a map of runtime-EXPRESSION keys =>
                    // path items). Both the callback-name and expression levels are
                    // arbitrary names, so walk the path items directly (in keyword
                    // position) rather than misreading an expression as a keyword.
                    if ($key === 'callbacks' && is_array($child)) {
                        foreach ($child as $callback) {
                            if (! is_array($callback)) {
                                continue;
                            }
                            if (isset($callback['$ref'])) {
                                $addComponent($callback['$ref'], 'callbacks'); // callback ref → callbacks only

                                continue;
                            }
                            foreach ($callback as $pathItem) {
                                // Each value is a callback path item — a path-item
                                // position (not a schema), so its top-level $ref may
                                // reuse a pathItems component. Its verb children are
                                // operations (security-requirement context starts at
                                // the verb level, computed in the generic recursion).
                                $walk($pathItem, false, true, false, false);
                            }
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
                            if (! str_contains($target, '/') && ! str_contains($target, '#')) {
                                $refs[] = 'schemas/'.$target;      // bare name => schema
                            } else {
                                $addComponent($target);            // explicit/relative component ref
                            }
                        }

                        continue;
                    }

                    // Data-bearing schema keywords hold free-form values (which
                    // may contain a $ref-shaped literal) — never refs. `default`
                    // is also a Response key, but `responses` is a name map so the
                    // default Response Object is still walked there, not here.
                    if (in_array($key, ['example', 'default', 'const', 'enum'], true)) {
                        continue;
                    }

                    // `examples`: meaning depends on context.
                    //  - In a Schema Object ($inSchema) it is the JSON Schema
                    //    keyword: an ARRAY of raw example DATA. A datum may itself be
                    //    an object containing a literal "$ref" — that is data, not a
                    //    reference, so we must skip it entirely (collecting it would
                    //    keep an otherwise-unreferenced schema → leak).
                    //  - In a Media Type / Parameter / Header Object it is a map of
                    //    Example Objects; an entry may be {$ref: #/components/examples/X}.
                    //    A digit-only/sequential-named map decodes to a PHP list, so
                    //    we iterate regardless of list-ness (NOT array_is_list) and
                    //    collect each entry's own $ref. We never recurse into an
                    //    example's `value`, so example DATA stays skipped either way.
                    if ($key === 'examples' && is_array($child)) {
                        if (! $inSchema) {
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
                $keyStr = (string) $key;
                $childKeysAreNames = ! $keysAreNames && in_array($keyStr, $nameMaps, true);
                // Enter (and stay in) schema context when descending through a
                // schema keyword. Only meaningful in keyword position — a property
                // literally NAMED "schema"/"items" is a name, not a keyword. Schema
                // context is monotonic downward (a subschema is always a schema).
                $childInSchema = $inSchema || (! $keysAreNames && (
                    $keyStr === 'schema'
                    || in_array($keyStr, $schemaSubMaps, true)
                    || in_array($keyStr, $schemaSubKeys, true)
                ));
                // A child keyed by an HTTP verb is an Operation Object ONLY when its
                // parent is a Path Item ($pathItemContext) — otherwise a nested
                // object literally named `get`/`post` (in a response/header/schema)
                // would be mistaken for an operation and its `security` annotation
                // wrongly read as a requirement. Its DIRECT `security` is then a real
                // requirement; the flag is single-level (sub-objects reset it).
                $childSecurityIsRequirement = $pathItemContext && ! $keysAreNames && in_array($keyStr, self::HTTP_VERBS, true);
                $walk($child, $childKeysAreNames, false, $childInSchema, $childSecurityIsRequirement); // sub-content is not a path-item position
            }
        };

        $walk($node, $keysAreNames, $pathItemContext, $inSchema, $securityIsRequirement);

        return ['refs' => $refs, 'anchors' => $anchors];
    }

    /**
     * Component reachability refs in a node (see walkReachability).
     *
     * @return list<string>
     */
    private function collectReachableRefs(mixed $node, bool $keysAreNames = false, bool $pathItemContext = false, bool $inSchema = false): array
    {
        return $this->walkReachability($node, $keysAreNames, $pathItemContext, $inSchema)['refs'];
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
        $fragmentPrefix = '/components/pathItems/';
        $seen = [];

        // Collect each link of the $ref chain as a precedence layer (closest
        // first) WITHOUT its own $ref. Invariant: NO path-item $ref survives —
        // a malformed / external / unsupported / cyclic ref is simply not
        // followed, so the filtered response can never point at unfiltered (or
        // pruning-reachable) content. Same-document refs (pure or relative) are
        // resolved; external/unsupported ones are dropped.
        $layers = [];
        while (true) {
            $ref = $item['$ref'] ?? null;
            unset($item['$ref']);
            $layers[] = $item;

            $fragment = is_string($ref) ? $this->localFragment($ref) : null;
            if ($fragment === null || ! str_starts_with($fragment, $fragmentPrefix)) {
                break; // no further (resolvable same-document path-item) ref
            }

            $name = substr($fragment, strlen($fragmentPrefix));
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
     * schemes ("javascript:…"), CREDENTIAL-bearing URLs ("https://user:pass@host",
     * which would ship credentials to every user's browser) and malformed values
     * parse_url tolerates ("https://exa mple.com") before they reach the spec.
     *
     * OpenAPI Server Variables ({var}) are allowed: they're substituted with a
     * placeholder before validation so a templated URL still passes.
     */
    private function isValidServerUrl(string $url): bool
    {
        // Substitute {server variables} so filter_var (which rejects braces) can
        // validate the URL syntax; validation runs on this normalised form while
        // the original templated URL is what gets stored.
        $normalized = (string) preg_replace('/\{[^{}]*\}/', '1', trim($url));

        $parts = parse_url($normalized);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false; // never expose embedded credentials client-side
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return filter_var($normalized, FILTER_VALIDATE_URL) !== false;
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
