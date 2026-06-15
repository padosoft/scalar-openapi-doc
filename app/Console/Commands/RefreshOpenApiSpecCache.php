<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenApiSpecService;
use Illuminate\Console\Command;

final class RefreshOpenApiSpecCache extends Command
{
    protected $signature = 'openapi:refresh {--stale : Also clear the stale cache copy used for stale-on-error fallback.}';

    protected $description = 'Clear the cached upstream OpenAPI spec.';

    public function handle(OpenApiSpecService $service): int
    {
        $service->flushCache(
            includeStale: (bool) $this->option('stale'),
        );

        $this->info('OpenAPI cache refreshed.');

        return self::SUCCESS;
    }
}
