<?php

declare(strict_types=1);

namespace App\Runners;

use App\Jobs\RunDiffReview;
use App\Models\Repository;
use App\Runners\Container\Trial;
use App\Runners\Diff\DiffReview;

/**
 * The review as one request to the AI provider's own API, with the pull request's
 * diff and nothing else: no clone, no container, no reviewer that reads the
 * repository. Quick and cheap, and blind to everything the diff does not show.
 */
class DiffRunner implements ReviewRunner
{
    use KeepsRuns;

    public function __construct(protected QueuedRuns $queued) {}

    public const NAME = 'diff';

    public function label(): string
    {
        return 'The diff alone, through the provider\'s API';
    }

    public function shortLabel(): string
    {
        return 'Diff';
    }

    public function description(): string
    {
        return 'Sends the pull request\'s diff to the AI provider in one request. No clone and no container: fast and cheap, but it cannot read the code around a change.';
    }

    public function setupHint(): string
    {
        return 'Set REVIEW_DIFF_ENABLED=true.';
    }

    public function available(): bool
    {
        return (bool) config('review.diff.enabled');
    }

    public function start(Repository $repository, string $branch, ?string $commit = null): ?int
    {
        $build = $this->nextBuild($repository);

        $this->markRunning($repository, $build);

        RunDiffReview::dispatch($repository->getKey(), $branch, $commit, $build, time())
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));

        $this->queued->announce($repository, $branch, $commit, $build, QueuedRuns::FOR_A_WORKER, self::NAME);

        return $build;
    }

    /**
     * Queues a review of the diff that is kept off the pull request, to try it.
     */
    public function startTrial(Repository $repository, string $branch, Trial $trial, ?string $title = null): int
    {
        $build = $this->nextBuild($repository);

        $this->markRunning($repository, $build);
        $this->queued->announceOwn($repository, $branch, $build, self::NAME, [
            'pull_request_id' => $trial->pullRequestId,
            'agent' => DiffReview::AGENT,
            'context' => ['pull_request' => ['id' => $trial->pullRequestId, 'title' => $title]],
        ]);

        RunDiffReview::dispatch($repository->getKey(), $branch, null, $build, time(), $trial)
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));

        return $build;
    }

    public function lifetime(Repository $repository): int
    {
        // The provider's own limit, with room for the wait for a worker.
        return (int) config('review.diff.timeout') + 900;
    }
}
