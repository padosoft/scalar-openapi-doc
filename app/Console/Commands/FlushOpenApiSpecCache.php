<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenApiSpecService;
use Illuminate\Console\Command;

final class FlushOpenApiSpecCache extends Command
{
    protected $signature = 'openapi:flush-spec-cache {--stale : Also clear the stale cache copy used for stale-on-error fallback.}';

    protected $description = 'Flush cached OpenAPI spec documents used by the Scalar proxy.';

    public function handle(OpenApiSpecService $openApiSpecService): int
    {
        $openApiSpecService->flushCache((bool) $this->option('stale'));

        $this->info('OpenAPI spec cache flushed.');

        return self::SUCCESS;
    }
}
