<?php

declare(strict_types=1);

namespace Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use RuntimeException;

/**
 * A Monolog handler that always fails on write — used to prove that a dead log
 * handler does not bubble out of a Log call when the stack ignores exceptions.
 */
final class ThrowingLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('Could not write to socket');
    }
}
