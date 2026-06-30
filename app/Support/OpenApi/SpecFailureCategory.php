<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

/**
 * Why the OpenAPI spec could not be loaded. Drives the human-readable label
 * shown (redacted) on the docs page and the user-grants form.
 */
enum SpecFailureCategory: string
{
    case Database = 'database';
    case ExternalApi = 'external_api';
    case InvalidSpec = 'invalid_spec';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Database unavailable',
            self::ExternalApi => 'Upstream API error',
            self::InvalidSpec => 'Invalid OpenAPI document',
            self::Unknown => 'Unexpected error',
        };
    }
}
