<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * HTTP verbs that identify an OpenAPI operation. Stored uppercase; an operation
 * is identified by the (method, path) pair.
 */
enum HttpVerb: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';

    /**
     * Verb values as a list, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
