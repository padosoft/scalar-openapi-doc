<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OpenApiSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class OpenApiCacheController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/openapi-cache');
    }

    public function destroy(Request $request, OpenApiSpecService $service): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_UNAUTHORIZED);
        }

        $actorId = $user->id;

        $service->flushCache(
            includeStale: true,
            actorId: $actorId,
        );

        return response()->json([
            'status' => 'cleared',
        ]);
    }
}
