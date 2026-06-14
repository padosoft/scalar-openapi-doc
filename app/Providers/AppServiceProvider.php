<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureScalarAuthorization();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureScalarAuthorization(): void
    {
        Gate::define('viewScalar', static function (User $user): bool {
            $adminRole = config()->string('openapi.admin_role', 'admin');
            $viewerRoles = array_values(array_filter(array_map(
                static fn (mixed $role): string => is_string($role) ? trim($role) : '',
                (array) config('openapi.viewer_roles', []),
            ), static fn (string $role): bool => $role !== ''));

            if ($adminRole !== '' && ! in_array($adminRole, $viewerRoles, true)) {
                $viewerRoles[] = $adminRole;
            }

            return $user->hasAnyRole($viewerRoles);
        });
    }
}
