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
];
