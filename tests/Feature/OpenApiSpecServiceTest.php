<?php

declare(strict_types=1);

use App\Services\OpenApiSpecService;

/*
|--------------------------------------------------------------------------
| Golden tests for OpenApiSpecService
|--------------------------------------------------------------------------
| The filter tests are pure: they pass the granted tags/endpoints (and the
| admin flag) explicitly, so no authenticated user is needed. They require
| config/openapi.php and the fixture at tests/Fixtures/openapi.json.
*/

function spec(): OpenApiSpecService
{
    return app(OpenApiSpecService::class);
}

/**
 * @return array<string, mixed>
 */
function fixtureSpec(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/openapi.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

// ---- metadata extraction ------------------------------------------------

it('extracts unique tags sorted alphabetically', function () {
    expect(spec()->extractTags(fixtureSpec()))->toBe(['Orders', 'Products', 'Users']);
});

it('extracts every operation as an endpoint, sorted by path then method', function () {
    $labels = collect(spec()->extractEndpoints(fixtureSpec()))->pluck('label')->all();

    expect($labels)->toBe([
        'GET /health',
        'GET /orders',
        'POST /orders',
        'DELETE /orders/{id}',
        'GET /orders/{id}',
        'GET /products',
        'POST /products',
        'GET /products/{id}',
    ]);
});

// ---- filter by TAG ------------------------------------------------------

it('keeps only operations of granted tags and prunes the rest', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect(['Orders']), collect([]));

    expect(array_keys($filtered['paths']))->toBe(['/orders', '/orders/{id}'])
        ->and($filtered['paths']['/orders'])->toHaveKeys(['get', 'post'])
        ->and($filtered['paths']['/orders/{id}'])->toHaveKey('parameters') // path-level preserved
        ->and(collect($filtered['tags'])->pluck('name')->all())->toBe(['Orders']);
});

it('prunes orphan components keeping only the transitively reachable ones', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect(['Orders']), collect([]));

    $schemas = array_keys($filtered['components']['schemas']);

    expect($schemas)->toContain('Order')   // referenced by the paths
        ->toContain('Error')               // referenced by Order (transitive $ref)
        ->not->toContain('Product')        // unreachable
        ->not->toContain('Legacy');        // orphan
});

// ---- filter by ENDPOINT -------------------------------------------------

it('keeps a single operation granted by explicit endpoint', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect([]), collect(['GET /products/{id}']));

    expect(array_keys($filtered['paths']))->toBe(['/products/{id}'])
        ->and($filtered['paths']['/products/{id}'])->toHaveKey('get')
        ->and(array_keys($filtered['components']['schemas']))->toBe(['Product']);
});

// ---- UNION (tag OR endpoint) -------------------------------------------

it('applies union semantics across tags and endpoints', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect(['Orders']), collect(['GET /products/{id}']));

    expect(array_keys($filtered['paths']))
        ->toBe(['/orders', '/orders/{id}', '/products/{id}'])
        ->and(array_keys($filtered['components']['schemas']))
        ->toContain('Order')->toContain('Error')->toContain('Product')
        ->not->toContain('Legacy');
});

// ---- tagless operations -------------------------------------------------

it('exposes a tagless operation only via explicit endpoint grant', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect([]), collect(['GET /health']));

    expect(array_keys($filtered['paths']))->toBe(['/health'])
        ->and($filtered)->not->toHaveKey('components'); // no $ref -> components removed
});

// ---- no grant -----------------------------------------------------------

it('returns an empty paths set when no grant is present', function () {
    $filtered = spec()->filterForUser(fixtureSpec(), collect([]), collect([]));

    expect($filtered['paths'])->toBe([])
        ->and($filtered['tags'])->toBe([])
        ->and($filtered)->not->toHaveKey('components');
});

// ---- admin bypass (B1: isAdmin param, no Auth dependency) ---------------

it('returns the full spec for an admin when admin_sees_all is on', function () {
    config(['openapi.admin_sees_all' => true]);

    $filtered = spec()->filterForUser(fixtureSpec(), collect([]), collect([]), isAdmin: true);

    // Nothing filtered: all paths survive despite empty grants.
    expect(array_keys($filtered['paths']))
        ->toBe(['/orders', '/orders/{id}', '/products', '/products/{id}', '/health']);
});

it('still filters an admin when admin_sees_all is off', function () {
    config(['openapi.admin_sees_all' => false]);

    $filtered = spec()->filterForUser(fixtureSpec(), collect(['Orders']), collect([]), isAdmin: true);

    expect(array_keys($filtered['paths']))->toBe(['/orders', '/orders/{id}']);
});

// ---- inject servers -----------------------------------------------------

it('overrides the servers array when injecting admin servers', function () {
    $servers = [
        ['url' => 'https://api.staging.example.com', 'description' => 'Staging'],
        ['url' => 'https://api.example.com', 'description' => 'Production'],
    ];

    $out = spec()->injectServers(fixtureSpec(), $servers);

    expect($out['servers'])->toBe($servers);
});
