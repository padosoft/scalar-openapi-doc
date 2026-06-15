<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthLogController;
use App\Http\Controllers\Admin\OpenApiCacheController;
use App\Http\Controllers\Admin\ScalarServerController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\OpenApiDocsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::middleware('role:'.config()->string('openapi.admin_role', 'admin'))->group(function (): void {
        Route::get('admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::get('admin/users/create', [AdminUserController::class, 'create'])->name('admin.users.create');
        Route::post('admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::get('admin/users/{user}/edit', [AdminUserController::class, 'edit'])->name('admin.users.edit');
        Route::put('admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');

        Route::get('openapi-cache', [OpenApiCacheController::class, 'index']);
        Route::delete('openapi-cache', [OpenApiCacheController::class, 'destroy']);

        Route::get('servers', [ScalarServerController::class, 'index'])->name('admin.servers.index');
        Route::get('servers/create', [ScalarServerController::class, 'create'])->name('admin.servers.create');
        Route::post('servers', [ScalarServerController::class, 'store'])->name('admin.servers.store');
        Route::get('servers/{server}/edit', [ScalarServerController::class, 'edit'])->name('admin.servers.edit');
        Route::put('servers/{server}', [ScalarServerController::class, 'update'])->name('admin.servers.update');
        Route::delete('servers/{server}', [ScalarServerController::class, 'destroy'])->name('admin.servers.destroy');

        Route::get('auth-logs', [AuthLogController::class, 'index'])->name('admin.auth-logs.index');

    });
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::prefix('api-docs')->group(function (): void {
        Route::get('openapi.json', [OpenApiDocsController::class, 'show'])->middleware('can:viewScalar');

        Route::middleware(
            'role:'.config()->string('openapi.admin_role', 'admin'),
        )->group(function (): void {
            Route::get('meta/tags', [OpenApiDocsController::class, 'metaTags']);
            Route::get('meta/endpoints', [OpenApiDocsController::class, 'metaEndpoints']);
            Route::match(['post', 'delete'], 'flush-cache', [OpenApiCacheController::class, 'destroy'])->name('api-docs.flush-cache');
        });
    });
});

require __DIR__.'/settings.php';
