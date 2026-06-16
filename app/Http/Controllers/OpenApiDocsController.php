<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ScalarServer;
use App\Models\User;
use App\Models\UserAllowedEndpoint;
use App\Models\UserAllowedTag;
use App\Services\OpenApiSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiDocsController extends Controller
{
    public function show(Request $request, OpenApiSpecService $service): JsonResponse
    {
        $user = $this->resolveUser($request);

        $isAdmin = $this->isAdmin($user);
        $grantedTags = $user->allowedTags
            ->map(static fn (UserAllowedTag $tag): string => $tag->tag)
            ->values();
        $grantedEndpoints = $user->allowedEndpoints
            ->map(
                static fn (UserAllowedEndpoint $endpoint): string => "{$endpoint->method->value} {$endpoint->path}"
            )
            ->values();

        /** @var Collection<int, string> $grantedTags */
        /** @var Collection<int, string> $grantedEndpoints */
        $spec = $service->filterForUser(
            $service->fetchRaw(),
            $grantedTags,
            $grantedEndpoints,
            $isAdmin,
        );

        // Per-user server visibility: admins see all active servers; a non-admin
        // sees only the active servers granted to them (none granted = none
        // shown). Filtering by id over the active set keeps a deactivated server
        // hidden even if it is still granted.
        $serversQuery = ScalarServer::query()
            ->where('is_active', true)
            ->orderBy('sort_order');

        if (! $isAdmin) {
            $allowedServerIds = $user->allowedServers()->pluck('scalar_servers.id')->all();
            $serversQuery->whereIn('id', $allowedServerIds);
        }

        /** @var list<array<string, mixed>> $servers */
        $servers = $serversQuery
            ->get(['url', 'description'])
            ->map(
                static fn (ScalarServer $server): array => [
                    'url' => $server->url,
                    'description' => $server->description,
                ],
            )
            ->values()
            ->all();

        $spec = $service->injectServers($spec, $servers);

        return response()->json($spec, Response::HTTP_OK, ['Cache-Control' => 'private,no-store']);
    }

    public function metaTags(OpenApiSpecService $service): JsonResponse
    {
        $tags = $service->extractTags($service->fetchRaw());

        return response()->json($tags);
    }

    public function metaEndpoints(OpenApiSpecService $service): JsonResponse
    {
        $endpoints = $service->extractEndpoints($service->fetchRaw());

        return response()->json($endpoints);
    }

    public function flushCache(OpenApiSpecService $service): JsonResponse
    {
        $user = $this->currentUser();
        $service->flushCache(
            includeStale: true,
            actorId: $user->getAuthIdentifier() !== null ? (int) $user->id : null,
        );

        return response()->json([
            'status' => 'cleared',
        ]);
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(config()->string('openapi.admin_role', 'admin'));
    }

    private function resolveUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }
}
