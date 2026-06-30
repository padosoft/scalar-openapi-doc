<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\ThrowingLogHandler;

/*
|--------------------------------------------------------------------------
| Infrastructure resilience: DB-down 503 mapping + log-handler hardening
|--------------------------------------------------------------------------
*/

it('renders a friendly 503 when the database connection is lost', function () {
    Route::middleware('web')->get('/__db_down_test', function (): void {
        throw new QueryException(
            'mysql',
            'select 1',
            [],
            new Exception("SQLSTATE[HY000] [2013] Lost connection to server at 'handshake: reading initial communication packet', system error: 111"),
        );
    });

    $response = $this->get('/__db_down_test');

    $response->assertStatus(503);
    $response->assertSee('temporarily unavailable', false);
});

it('does not map an ordinary exception to 503', function () {
    Route::middleware('web')->get('/__boom_test', function (): void {
        throw new RuntimeException('ordinary failure');
    });

    $response = $this->get('/__boom_test');

    $response->assertStatus(500);
});

it('does not bubble a failing log handler when the stack ignores exceptions', function () {
    config()->set('logging.channels.boom', ['driver' => 'monolog', 'handler' => ThrowingLogHandler::class]);
    config()->set('logging.channels.safe_stack', ['driver' => 'stack', 'channels' => ['boom'], 'ignore_exceptions' => true]);

    Log::channel('safe_stack')->error('should be swallowed');

    expect(true)->toBeTrue();
});

it('bubbles a failing log handler when the stack does not ignore exceptions', function () {
    config()->set('logging.channels.boom2', ['driver' => 'monolog', 'handler' => ThrowingLogHandler::class]);
    config()->set('logging.channels.unsafe_stack', ['driver' => 'stack', 'channels' => ['boom2'], 'ignore_exceptions' => false]);

    expect(fn () => Log::channel('unsafe_stack')->error('should bubble'))
        ->toThrow(RuntimeException::class);
});

it('keeps the production stack channel configured to ignore handler failures', function () {
    expect(config('logging.channels.stack.ignore_exceptions'))->toBeTrue();
});
