<?php

namespace App\Console\Commands;

use App\Models\ReviewRun;
use Illuminate\Console\Command;

class SweepStaleReviewRuns extends Command
{
    protected $signature = 'review:sweep-runs';

    protected $description = 'Close review runs that started but never reported back';

    /**
     * A run reports its own result, and a job that fails closes its run — but
     * nothing tells the server about a worker that died without either. Those
     * rows would stay `running` forever and skew every graph, so anything older than
     * the review step could possibly take is closed as a timeout.
     */
    public function handle(): int
    {
        $cutoff = now()->subMinutes(ReviewRun::TIMEOUT_MINUTES);

        // A queued run may wait behind a whole review and for a worker; one this old
        // was started by a job that is no longer there to begin it.
        $stale = ReviewRun::running()
            ->where('started_at', '<', $cutoff)
            ->get()
            ->merge(ReviewRun::query()
                ->where('status', ReviewRun::STATUS_QUEUED)
                ->where('started_at', '<', now()->subMinutes(ReviewRun::QUEUED_TIMEOUT_MINUTES))
                ->get());

        foreach ($stale as $run) {
            $run->finish(ReviewRun::STATUS_TIMEOUT);

            $this->line("Timed out {$run->workspace}/{$run->slug} #{$run->build_number} on {$run->commit} (started {$run->started_at})");
        }

        $this->info($stale->isEmpty()
            ? 'No stale review runs.'
            : "Closed {$stale->count()} stale review run(s).");

        return self::SUCCESS;
    }
}
