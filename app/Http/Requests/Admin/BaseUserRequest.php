<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\ScalarServer;
use App\Models\User;
use App\Models\UserAllowedEndpoint;
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
        $allowedTags = $this->grantableTags();
        $allowedEndpoints = $this->grantableEndpoints();

        if ($allowedTags === []) {
            $allowedTags = array_values(array_unique(array_merge($allowedTags, $this->existingGrantedTags())));
        }

        if ($allowedEndpoints === []) {
            $allowedEndpoints = array_values(array_unique(
                array_merge($allowedEndpoints, $this->existingGrantedEndpointKeys()),
            ));
        }

        return [
            'grants' => ['array'],
            'grants.tags' => ['array'],
            'grants.tags.*' => [
                'string',
                'max:255',
                Rule::in($allowedTags),
            ],
            'grants.endpoints' => ['array'],
            'grants.endpoints.*' => [
                'string',
                'max:255',
                Rule::in($allowedEndpoints),
            ],
            'grants.servers' => ['array'],
            // Anti-tampering: only servers the admin could actually select are
            // grantable — active servers, plus this user's already-assigned
            // servers (so an existing grant to a since-deactivated server can
            // still be re-submitted from the edit form without being rejected).
            'grants.servers.*' => [
                'integer',
                Rule::in($this->grantableServerIds()),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function grantableServerIds(): array
    {
        /** @var list<mixed> $active */
        $active = ScalarServer::query()
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $unique = [];
        foreach ([...$active, ...$this->existingGrantedServerIds()] as $id) {
            $int = $this->toPositiveInt($id);
            if ($int !== null) {
                $unique[$int] = $int;
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<int>
     */
    private function existingGrantedServerIds(): array
    {
        $user = $this->route('user');
        if (! $user instanceof User) {
            return [];
        }

        $ids = [];
        foreach ($user->allowedServers->modelKeys() as $id) {
            $int = $this->toPositiveInt($id);
            if ($int !== null) {
                $ids[] = $int;
            }
        }

        return $ids;
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function existingGrantedTags(): array
    {
        $user = $this->route('user');
        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            $user->allowedTags->pluck('tag')->all(),
            static fn (mixed $tag): bool => is_string($tag),
        ));
    }

    /**
     * @return list<string>
     */
    private function existingGrantedEndpointKeys(): array
    {
        $user = $this->route('user');
        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_map(
            static fn (UserAllowedEndpoint $endpoint): string => $endpoint->method->value.' '.$endpoint->path,
            $user->allowedEndpoints->all(),
        ));
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

    /**
     * Granted scalar_servers primary keys (validated to exist by grantRules).
     *
     * @return list<int>
     */
    public function serverPayload(): array
    {
        $raw = $this->input('grants.servers', []);
        $values = is_array($raw) ? array_values($raw) : [];

        $ids = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $int = (int) $value;
                if ($int > 0) {
                    $ids[$int] = $int;
                }
            }
        }

        return array_values($ids);
    }
}
