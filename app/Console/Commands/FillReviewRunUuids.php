<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReviewRun;
use Illuminate\Console\Command;

class FillReviewRunUuids extends Command
{
    protected $signature = 'review:fill-run-uuids';

    protected $description = 'Give the review runs from before runs had a uuid theirs';

    /**
     * Safe to run again: a run that has one keeps it.
     */
    public function handle(): int
    {
        $filled = 0;

        ReviewRun::query()->whereNull('uuid')->chunkById(200, function ($runs) use (&$filled): void {
            foreach ($runs as $run) {
                $run->uuid = $run->newUniqueId();
                $run->save();
                $filled++;
            }
        });

        $this->info("Gave {$filled} run(s) a uuid.");

        return self::SUCCESS;
    }
}
