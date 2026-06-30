<?php

declare(strict_types=1);

use App\Services\OpenApiSpecService;
use App\Support\OpenApi\SpecFailureCategory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| tryFetchRaw(): non-throwing, categorized spec loading
|--------------------------------------------------------------------------
*/

function degradationService(): OpenApiSpecService
{
    return app(OpenApiSpecService::class);
}

function configureUpstream(string $url): void
{
    $parts = parse_url($url);

    config([
        'openapi.cache_key' => 'degradation-test-raw',
        'openapi.stale_key' => 'degradation-test-stale',
        'openapi.upstream_url' => $url,
        'openapi.allowed_hosts' => [$parts['host'] ?? '127.0.0.1'],
        'openapi.allowed_schemes' => [$parts['scheme'] ?? 'http'],
    ]);
    Cache::forget('degradation-test-raw');
    Cache::forget('degradation-test-stale');
}

it('returns a successful result from cache', function () {
    config(['openapi.cache_key' => 'degradation-ok-raw']);
    Cache::put('degradation-ok-raw', ['openapi' => '3.1.0', 'info' => ['title' => 'A', 'version' => '1'], 'paths' => []], 3600);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeTrue();
    expect($result->specOrFail()['info']['title'])->toBe('A');
});

it('categorizes an upstream HTTP error as ExternalApi with the status code', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json');
    Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response([], 503)]);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeFalse();
    expect($result->failureOrFail()->category)->toBe(SpecFailureCategory::ExternalApi);
    expect($result->failureOrFail()->httpStatus)->toBe(503);
});

it('categorizes a connection failure as ExternalApi without a status', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json');
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeFalse();
    expect($result->failureOrFail()->category)->toBe(SpecFailureCategory::ExternalApi);
    expect($result->failureOrFail()->httpStatus)->toBeNull();
});

it('categorizes a non-OpenAPI payload as InvalidSpec', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json');
    Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response(['not' => 'a spec'], 200)]);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeFalse();
    expect($result->failureOrFail()->category)->toBe(SpecFailureCategory::InvalidSpec);
});

it('categorizes a dropped database connection as Database', function () {
    config(['openapi.cache_key' => 'degradation-db-raw']);

    $repo = Mockery::mock(Repository::class);
    $repo->shouldReceive('get')
        ->andThrow(new QueryException('mysql', 'select 1', [], new Exception('Lost connection to server')));
    Cache::shouldReceive('store')->andReturn($repo);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeFalse();
    expect($result->failureOrFail()->category)->toBe(SpecFailureCategory::Database);
});

it('serves the stale copy on upstream failure', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json');
    Cache::forever('degradation-test-stale', ['openapi' => '3.1.0', 'info' => ['title' => 'Stale', 'version' => '1'], 'paths' => []]);
    Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response([], 500)]);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeTrue();
    expect($result->specOrFail()['info']['title'])->toBe('Stale');
});

it('returns the freshly fetched spec even when caching it fails', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json');
    Http::fake(['http://127.0.0.1:1/openapi.json' => Http::response(
        ['openapi' => '3.1.0', 'info' => ['title' => 'Fresh', 'version' => '1'], 'paths' => []],
        200,
    )]);

    // Cache read misses, but every write to the (DB-backed) cache fails.
    $repo = Mockery::mock(Repository::class);
    $repo->shouldReceive('get')->andReturnNull();
    $repo->shouldReceive('put')->andThrow(new QueryException('mysql', 'insert into cache', [], new Exception('MySQL server has gone away')));
    $repo->shouldReceive('forever')->andThrow(new QueryException('mysql', 'insert into cache', [], new Exception('MySQL server has gone away')));
    Cache::shouldReceive('store')->andReturn($repo);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeTrue();
    expect($result->specOrFail()['info']['title'])->toBe('Fresh');
});

it('redacts secrets from the failure message', function () {
    configureUpstream('http://127.0.0.1:1/openapi.json?token=SUPERSECRET');
    Http::fake(['http://127.0.0.1:1/*' => Http::response([], 500)]);

    $result = degradationService()->tryFetchRaw();

    expect($result->ok())->toBeFalse();
    expect($result->failureOrFail()->message)->not->toContain('SUPERSECRET');
});
