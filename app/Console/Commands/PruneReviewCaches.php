<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runners\Container\ComposerCache;
use App\Runners\Container\ReviewSessions;
use Illuminate\Console\Command;

class PruneReviewCaches extends Command
{
    protected $signature = 'review:prune-caches';

    protected $description = 'Remove the Composer caches and the reviewer sessions that had no container review for the days they are kept';

    public function handle(ComposerCache $caches, ReviewSessions $sessions): int
    {
        $this->info("Removed {$caches->prune()} cache(s).");
        $this->info("Removed {$sessions->prune()} session(s).");

        return self::SUCCESS;
    }
}
