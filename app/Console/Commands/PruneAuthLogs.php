<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuthLog;
use Illuminate\Console\Command;

final class PruneAuthLogs extends Command
{
    protected $signature = 'auth-logs:prune {--days=30 : Remove entries older than this many days}';

    protected $description = 'Delete old authentication audit logs.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $days = max(1, $days);

        $cutoff = now()->subDays($days);

        $removed = AuthLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $removedRows = is_int($removed) ? $removed : 0;
        $this->info('Removed '.$removedRows.' auth log rows older than '.$days.' day(s).');

        return self::SUCCESS;
    }
}
