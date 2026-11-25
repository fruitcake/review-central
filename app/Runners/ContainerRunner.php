<?php

declare(strict_types=1);

namespace App\Runners;

use App\Jobs\RunContainerReview;
use App\Jobs\RunSecurityReview;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\Trial;
use App\Runners\Security\SecurityReview;
use App\Services\ReviewSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The review as a queued job on this installation's own worker: it clones the
 * repository and runs the reviewer in a sandbox, here Docker with gVisor on the
 * worker itself. The repository needs nothing of its own; the webhook starts it.
 *
 * CloudflareRunner is the same with the reviewer in a Cloudflare sandbox: which
 * one a job runs in is the repository's runner, or the trial's.
 */
class ContainerRunner implements ReviewRunner
{
    use KeepsRuns;

    public function __construct(protected QueuedRuns $queued) {}

    public const NAME = 'docker';

    /** The name a review's record goes by, and its sandbox. */
    public function name(): string
    {
        return static::NAME;
    }

    public function label(): string
    {
        return 'Docker on this server';
    }

    public function shortLabel(): string
    {
        return 'Docker';
    }

    public function description(): string
    {
        return 'The reviewer reads the repository in a sandboxed container on this installation\'s own worker. Needs nothing in the repository but the connection\'s webhook.';
    }

    public function setupHint(): string
    {
        return 'Once the worker has Docker, gVisor and the reviewer\'s image, set REVIEW_DOCKER_ENABLED=true. Admin → Setup says what is missing.';
    }

    public function available(): bool
    {
        return (bool) config('review.docker.enabled');
    }

    public function start(Repository $repository, string $branch, ?string $commit = null): ?int
    {
        // The number the run goes by, as a build number: it holds
        // the review lock and names the run's record.
        $build = $this->nextBuild($repository);

        $this->markRunning($repository, $build);

        // A job that is reviewing this branch carries on with it when it is done,
        // rather than a second job starting from a fresh clone beside it.
        $overtaken = null;

        $handed = $this->followUps($repository) > 0 && Cache::lock($this->claimLock($repository, $branch), 10)->block(5, function () use ($repository, $branch, $commit, $build, &$overtaken): bool {
            if (! Cache::has($this->claim($repository, $branch))) {
                return false;
            }

            // The newest wins: an earlier one that was waiting is inside its diff.
            $earlier = Cache::get($this->followUp($repository, $branch));

            if (is_array($earlier)) {
                $this->markEnded($repository, (int) $earlier['build']);
                $overtaken = (int) $earlier['build'];
            }

            Cache::put($this->followUp($repository, $branch), ['build' => $build, 'commit' => $commit, 'queued_at' => time()], $this->lifetime($repository));

            return true;
        });

        if (! $handed) {
            $this->dispatch($repository, $branch, $commit, $build);
        }

        // Said at once, on the pull request and in the panel: the review itself may
        // not begin for as long as the one before it takes.
        $announced = $this->queued->announce($repository, $branch, $commit, $build, $handed ? QueuedRuns::FOR_THE_REVIEW_BEFORE_IT : QueuedRuns::FOR_A_WORKER, static::NAME);

        if ($overtaken !== null) {
            // Named, so the overtaken review's page says which commit took its place.
            $this->queued->close($repository, $overtaken, ReviewRun::STATUS_SKIPPED, 'A newer commit is reviewed instead', $announced ?? $commit);
        }

        return $build;
    }

    public function dispatch(Repository $repository, string $branch, ?string $commit, int $build): void
    {
        RunContainerReview::dispatch($repository->getKey(), $branch, $commit, $build, time(), 1 + $this->followUps($repository))
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    /**
     * Queues a review that is kept off the pull request. Always a job of its own:
     * it is not the review of the branch, so the job doing that does not take it.
     */
    public function startTrial(Repository $repository, string $branch, Trial $trial, ?string $title = null): int
    {
        $build = $this->nextBuild($repository);

        $this->markRunning($repository, $build);
        $this->queued->announceOwn($repository, $branch, $build, static::NAME, [
            'pull_request_id' => $trial->pullRequestId,
            'agent' => $trial->provider,
            'context' => ['pull_request' => ['id' => $trial->pullRequestId, 'title' => $title]],
        ]);

        RunContainerReview::dispatch($repository->getKey(), $branch, $trial->commit, $build, time(), 1, $trial)
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));

        return $build;
    }

    /**
     * Starts a security review of a branch as it stands: the same worker and
     * sandbox as a pull request's review, and no pull request.
     */
    public function startSecurityReview(Repository $repository, string $branch): int
    {
        $build = $this->nextBuild($repository);

        $this->markRunning($repository, $build);
        $this->queued->announceOwn($repository, $branch, $build, static::NAME, [
            'kind' => SecurityReview::KIND, 'agent' => 'claude-code', 'provider' => 'anthropic',
            'context' => ['pull_request' => ['title' => "Security review of {$branch}"]],
        ]);

        RunSecurityReview::dispatch($repository->getKey(), $branch, $build, time())
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));

        return $build;
    }

    /**
     * How many reviews a job for this repository may carry on with after its own.
     */
    public function followUps(Repository $repository): int
    {
        return ReviewSettings::followUps($repository);
    }

    /**
     * Says a job is reviewing this branch, so a review asked for meanwhile is
     * handed to it.
     */
    public function claimBranch(Repository $repository, string $branch): void
    {
        // For one review, and claimed again for each the job carries on with. A job
        // that is killed outright then holds the branch for one review's time, not
        // for all it might have done: every push in between would wait for nobody.
        Cache::put($this->claim($repository, $branch), true, $this->roundLifetime());
    }

    /**
     * The review that was asked for on this branch while the job was busy, for the
     * job to carry on with. With none, or when the job stops here, the branch is
     * let go in the same breath: a review asked for after that starts its own job.
     *
     * @return array{build: int, commit: string|null, queued_at: int|null}|null
     */
    public function nextFollowUp(Repository $repository, string $branch, bool $carryOn = true): ?array
    {
        return Cache::lock($this->claimLock($repository, $branch), 10)->block(5, function () use ($repository, $branch, $carryOn): ?array {
            $next = Cache::pull($this->followUp($repository, $branch));
            $next = is_array($next) ? [
                'build' => (int) $next['build'],
                'commit' => is_string($next['commit'] ?? null) ? $next['commit'] : null,
                'queued_at' => is_int($next['queued_at'] ?? null) ? $next['queued_at'] : null,
            ] : null;

            if ($next === null || ! $carryOn) {
                Cache::forget($this->claim($repository, $branch));
            } else {
                $this->claimBranch($repository, $branch);
            }

            return $next;
        });
    }

    /**
     * Notes which review a job has carried on with, under the build it started as:
     * a job that dies is only known by that one.
     */
    public function noteRound(Repository $repository, int $first, int $build, ?string $commit): void
    {
        Cache::put($this->round($repository, $first), ['build' => $build, 'commit' => $commit], $this->lifetime($repository));
    }

    /**
     * @return array{build: int, commit: string|null}|null the review the job had carried on with, if any
     */
    public function forgetRound(Repository $repository, int $first): ?array
    {
        $round = Cache::pull($this->round($repository, $first));

        return is_array($round)
            ? ['build' => (int) $round['build'], 'commit' => is_string($round['commit'] ?? null) ? $round['commit'] : null]
            : null;
    }

    /**
     * Seconds a run may take from the moment it is queued: the reviewer's own
     * time, with room for the clone and the wait for a worker.
     */
    public function lifetime(Repository $repository): int
    {
        // A follow-up waits for the review before it, and that one for the one before.
        return $this->roundLifetime() * (1 + $this->followUps($repository));
    }

    /**
     * Seconds one review may take: the reviewer's own time, with room for the clone
     * and the wait for a worker.
     */
    public function roundLifetime(): int
    {
        return (int) config('review.container.timeout') + 900;
    }

    protected function claim(Repository $repository, string $branch): string
    {
        return "review-container-claim:{$repository->getKey()}:".sha1($branch);
    }

    protected function followUp(Repository $repository, string $branch): string
    {
        return "review-container-follow-up:{$repository->getKey()}:".sha1($branch);
    }

    protected function round(Repository $repository, int $first): string
    {
        return "review-container-round:{$repository->getKey()}:{$first}";
    }

    protected function claimLock(Repository $repository, string $branch): string
    {
        return "review-container-claim-lock:{$repository->getKey()}:".sha1($branch);
    }
}
