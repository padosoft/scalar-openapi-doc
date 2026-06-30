<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\DetectsLostConnections;
use Throwable;

/**
 * Thin, injectable wrapper around Laravel's lost-connection heuristics, so the
 * global exception handler can map a dropped DB connection (e.g. Laravel Cloud
 * "Lost connection ... reading initial communication packet") to a friendly 503
 * instead of a raw 500 — while ordinary query errors keep their normal handling.
 */
final class LostConnectionDetector
{
    use DetectsLostConnections;

    public function causedBy(Throwable $e): bool
    {
        return $this->causedByLostConnection($e);
    }
}
