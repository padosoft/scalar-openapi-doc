<?php

declare(strict_types=1);

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
            Route::post('flush-cache', [OpenApiDocsController::class, 'flushCache']);
        });
    });
});

require __DIR__.'/settings.php';
