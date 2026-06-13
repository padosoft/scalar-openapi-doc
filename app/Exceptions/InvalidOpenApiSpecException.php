<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the upstream returns something that is not a usable OpenAPI spec
 * (anti cache-poisoning: we never cache a non-spec payload).
 */
final class InvalidOpenApiSpecException extends RuntimeException {}
