<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | RBAC role names
    |--------------------------------------------------------------------------
    |
    | The application roles. `admin_role` is the privileged role (manages users,
    | servers, audit logs, cache) and — when `admin_sees_all` is true (T4) —
    | bypasses the per-user OpenAPI spec filter. `viewer_roles` are the roles
    | allowed to read the API documentation. Kept configurable so nothing is
    | hardcoded to the literal string "admin".
    |
    */

    'admin_role' => trim((string) env('OPENAPI_ADMIN_ROLE', 'admin')) ?: 'admin',

    'viewer_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPENAPI_VIEWER_ROLES', 'admin,user'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Initial admin user (seeded)
    |--------------------------------------------------------------------------
    |
    | Credentials for the admin user created by AdminUserSeeder. Read through
    | config (not env() directly in the seeder) so it works when the config
    | cache is warm.
    |
    */

    'admin_user' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD', 'change-me'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream OpenAPI source
    |--------------------------------------------------------------------------
    |
    | External URL returning the full OpenAPI JSON. Our proxy route
    | (/api-docs/openapi.json) fetches it, filters it per user, and hands it to
    | Scalar. The browser never receives the unfiltered spec.
    |
    */

    'upstream_url' => env('OPENAPI_UPSTREAM_URL'),

    /*
    |--------------------------------------------------------------------------
    | Cache (Redis in production; driver-agnostic via the Cache facade)
    |--------------------------------------------------------------------------
    |
    | cache_ttl  -> TTL (seconds) of the cached upstream copy (default 1h).
    | cache_key  -> expiring copy; stale_key -> never-expiring emergency copy
    | served on upstream failure (stale-on-error).
    |
    */

    'cache_ttl' => (int) env('OPENAPI_CACHE_TTL', 3600),
    'cache_key' => 'openapi:spec:raw',
    'stale_key' => 'openapi:spec:stale',

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http_timeout' => (int) env('OPENAPI_HTTP_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Authentication header towards the external OpenAPI server
    |--------------------------------------------------------------------------
    |
    | If `token` is set, it is sent as the `name` header on every fetch so the
    | upstream can accept only authenticated requests. The token stays
    | server-side (never exposed to the browser).
    |
    */

    'auth_header' => [
        'name' => (string) env('OPENAPI_AUTH_HEADER_NAME', 'X-Api-Token'),
        'token' => env('OPENAPI_AUTH_HEADER_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filter behaviour
    |--------------------------------------------------------------------------
    |
    | admin_sees_all  -> users with the admin role receive the full spec.
    | prune_components -> remove orphan components after filtering the paths.
    |
    */

    'admin_sees_all' => (bool) env('OPENAPI_ADMIN_SEES_ALL', true),
    'prune_components' => (bool) env('OPENAPI_PRUNE_COMPONENTS', true),
];
