<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Database\LostConnectionDetector;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withSchedule(function ($schedule): void {
        $schedule->command('auth-logs:prune --days=30')
            ->dailyAt('03:00')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // spatie/laravel-permission middleware aliases (not auto-registered).
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A dropped DB connection (Laravel Cloud "Lost connection ... reading
        // initial communication packet") is a drastic, rare event: render one
        // clean 503 (shown at login and everywhere) instead of a raw 500 with a
        // stack trace. Only connection-level failures map here — ordinary query
        // errors keep their normal handling so real bugs aren't masked.
        $exceptions->render(function (Throwable $e, Request $request): ?SymfonyResponse {
            if ($request->is('api/*') || ! app(LostConnectionDetector::class)->causedBy($e)) {
                return null;
            }

            return response()->view('errors.503', [], SymfonyResponse::HTTP_SERVICE_UNAVAILABLE);
        });
    })->create();
