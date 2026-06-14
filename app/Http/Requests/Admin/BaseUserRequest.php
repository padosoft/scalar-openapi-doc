<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\OpenApiSpecService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Stringable;

/**
 * Shared helpers for admin user mutation requests.
 */
abstract class BaseUserRequest extends FormRequest
{
    /** @var string */
    protected const ENDPOINT_SEPARATOR = ' ';

    protected function adminRole(): string
    {
        $role = config('openapi.admin_role');

        return is_string($role) && $role !== '' ? $role : 'admin';
    }

    /**
     * @return list<string>
     */
    protected function grantableTags(): array
    {
        try {
            $service = app(OpenApiSpecService::class);
            $spec = $service->fetchRaw();
            $tags = $service->extractTags($spec);
        } catch (\Throwable) {
            return [];
        }

        sort($tags, SORT_STRING);

        return array_values(array_unique($tags));
    }

    /**
     * Canonical endpoint grant values for validation, format: "METHOD path".
     *
     * @return list<string>
     */
    protected function grantableEndpoints(): array
    {
        try {
            $service = app(OpenApiSpecService::class);
            $spec = $service->fetchRaw();
            $endpoints = $service->extractEndpoints($spec);
        } catch (\Throwable) {
            return [];
        }

        $values = array_map(
            static fn (array $endpoint): string => sprintf(
                '%s %s',
                $endpoint['method'],
                $endpoint['path'],
            ),
            $endpoints,
        );

        sort($values, SORT_STRING);

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    protected function allowedRoles(): array
    {
        $guard = config('auth.defaults.guard');
        if (! is_string($guard) || $guard === '') {
            $guard = 'web';
        }

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $result = [];
        foreach ($roles as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Standardized grant validation arrays.
     *
     * @return array<string, list<string|Stringable>>
     */
    protected function grantRules(): array
    {
        return [
            'grants' => ['array'],
            'grants.tags' => ['array'],
            'grants.tags.*' => [
                'string',
                'max:255',
                Rule::in($this->grantableTags()),
            ],
            'grants.endpoints' => ['array'],
            'grants.endpoints.*' => [
                'string',
                'max:255',
                Rule::in($this->grantableEndpoints()),
            ],
        ];
    }

    /**
     * Guard a single endpoint grant string into (method,path).
     *
     * @return array{method:string, path:string}|null
     */
    protected function parseEndpointGrant(string $grant): ?array
    {
        $parts = explode(self::ENDPOINT_SEPARATOR, trim($grant), 2);
        if (count($parts) !== 2) {
            return null;
        }

        $method = strtoupper(trim($parts[0]));
        $path = trim($parts[1]);
        if ($method === '' || $path === '') {
            return null;
        }

        return [
            'method' => $method,
            'path' => $path,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array{method: string, path: string}>
     */
    protected function parseGrantedEndpoints(array $values): array
    {
        $rows = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $parsed = $this->parseEndpointGrant($value);
            if ($parsed === null) {
                continue;
            }

            $key = $parsed['method'].' '.$parsed['path'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = $parsed;
        }

        return $rows;
    }

    /**
     * @return list<array{method:string,path:string}>
     */
    public function endpointPayload(): array
    {
        $raw = $this->input('grants.endpoints', []);
        $endpoints = is_array($raw) ? array_values($raw) : [];

        return $this->parseGrantedEndpoints($endpoints);
    }

    /**
     * @return list<string>
     */
    public function tagPayload(): array
    {
        return array_values(array_filter(
            (array) $this->input('grants.tags', []),
            static fn (mixed $tag): bool => is_string($tag) && trim($tag) !== '',
        ));
    }
}
