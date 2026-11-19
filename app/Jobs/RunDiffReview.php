<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Runners\Container\Trial;
use App\Runners\Diff\DiffReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One review of a pull request's diff, through the provider's API.
 *
 * Tried once: a review that failed halfway may have posted, and the next push or a
 * `/review` comment starts a clean one.
 */
class RunDiffReview implements ShouldQueue
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
        public ?Trial $trial = null,
    ) {
        // Above the provider's own limit, so the job is never killed over an
        // answer that is still coming.
        $this->timeout = (int) config('review.diff.timeout') + 300;
    }

    public function handle(DiffReview $review): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        // Removed while the job waited.
        if ($repository === null) {
            return;
        }

        $review->run($repository, $this->branch, $this->commit, $this->build, $this->queuedAt === null ? null : max(0, time() - $this->queuedAt), $this->trial);
    }

    public function failed(?Throwable $exception): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository !== null) {
            app(DiffReview::class)->abandon($repository, $this->branch, $this->build, $this->trial !== null);
        }
    }
}
