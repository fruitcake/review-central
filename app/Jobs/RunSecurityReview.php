<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Runners\Security\SecurityReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reviews a branch for its security, as it stands.
 *
 * Tried once: a second try is a second review, and costs one.
 */
class RunSecurityReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $repositoryId,
        public string $branch,
        public int $build,
        // When the run was queued, to know how long it waited for a worker.
        public ?int $queuedAt = null,
    ) {
        // Above the reviewer's own limit, with room for the clone.
        $this->timeout = (int) config('review.security.timeout') + 600;
    }

    public function handle(SecurityReview $review): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        // Removed while the job waited.
        if ($repository === null) {
            return;
        }

        $review->run($repository, $this->branch, $this->build, $this->queuedAt === null ? null : max(0, time() - $this->queuedAt));
    }

    public function failed(?Throwable $exception): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository !== null) {
            app(SecurityReview::class)->abandon($repository, $this->build);
        }
    }
}
