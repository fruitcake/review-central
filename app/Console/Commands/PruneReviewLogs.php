<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runners\Container\RunLog;
use Illuminate\Console\Command;

class PruneReviewLogs extends Command
{
    protected $signature = 'review:prune-logs';

    protected $description = 'Remove the logs of container reviews older than the days they are kept';

    public function handle(RunLog $logs): int
    {
        $this->info("Removed {$logs->prune()} log(s).");

        return self::SUCCESS;
    }
}
