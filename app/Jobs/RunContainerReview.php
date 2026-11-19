<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Runners\Container\ContainerReview;
use App\Runners\Container\Trial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One review on the container runner.
 *
 * Tried once: a review that failed halfway may have posted, and the next push or a
 * `/review` comment starts a clean one.
 */
class RunContainerReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $repositoryId,
        public string $branch,
        public ?string $commit,
        public int $build,
        // When the run was queued, to know how long it waited for a worker.
        public ?int $queuedAt = null,
        // The reviews this job may do: its own, and the ones it may carry on with.
        public int $rounds = 1,
        public ?Trial $trial = null,
    ) {
        // Above the reviewer's own limit, so the job is never killed over a
        // reviewer that is still within it. The queue connection's retry_after
        // has to be above this in turn.
        $this->timeout = ((int) config('review.container.timeout') + 600) * max(1, $this->rounds);
    }

    public function handle(ContainerReview $review): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        // Removed while the job waited.
        if ($repository === null) {
            return;
        }

        $review->run($repository, $this->branch, $this->commit, $this->build, $this->queuedAt === null ? null : max(0, time() - $this->queuedAt), $this->rounds, $this->trial);
    }

    public function failed(?Throwable $exception): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository !== null) {
            app(ContainerReview::class)->abandon($repository, $this->branch, $this->commit, $this->build, $this->rounds, $this->trial !== null);
        }
    }
}
