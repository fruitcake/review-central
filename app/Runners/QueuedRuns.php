<?php

declare(strict_types=1);

namespace App\Runners;

use App\Enums\BuildState;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewContext;
use Throwable;

/**
 * The reviews that were started and have not begun: waiting for a worker, or for
 * the review of the commit before theirs.
 *
 * A review only gets its record when it asks for its context, which can be half an
 * hour after the push that started it. Until then the pull request and the panel
 * would say nothing at all, and the push would look ignored. So a started review
 * is recorded here at once, as `queued`, and shown on its commit as in progress;
 * the review takes that record over when it begins.
 */
class QueuedRuns
{
    public const FOR_A_WORKER = 'Waiting for a worker';

    public const FOR_THE_REVIEW_BEFORE_IT = 'Waiting for the review of the commit before it';

    public function __construct(protected RunConclusion $conclusion) {}

    /**
     * Records a review that was started, and says so on its commit.
     *
     * Best effort: the review runs the same without it, and a host that cannot be
     * asked now is asked again when the review begins.
     *
     * @param  string|null  $commit  the commit to review; null for the head of the branch's pull request
     * @return string|null the commit the review was recorded for, when it was
     */
    public function announce(Repository $repository, string $branch, ?string $commit, int $build, string $waiting, ?string $runner = null): ?string
    {
        try {
            $pullRequest = $repository->sourceControl()->findOpenPullRequestByBranch($branch);
            $head = (string) ($commit ?? $pullRequest['source']['commit']['hash'] ?? '');

            // Nothing a review could be recorded against; it will find that out itself.
            if ($pullRequest === null || $head === '') {
                return null;
            }

            $commit = $repository->sourceControl()->resolveCommit($head) ?? $head;
            $reviewed = $this->reviewed($repository, (int) $pullRequest['id'], $commit);

            ReviewRun::query()->create([
                'tenant_id' => $repository->tenant_id,
                'repository_id' => $repository->getKey(),
                'workspace' => $repository->workspace,
                'slug' => $repository->slug,
                'branch' => $branch,
                'commit' => $commit,
                'build_number' => $build,
                'pull_request_id' => (int) $pullRequest['id'],
                'status' => ReviewRun::STATUS_QUEUED,
                'runner' => $runner ?? $repository->runnerName(),
                'started_at' => now(),
            ]);

            // A commit that was reviewed keeps the status that review gave it: this
            // one may well be told there is nothing new to review.
            if (! $reviewed) {
                $this->conclusion->show($repository, $commit, $build, BuildState::InProgress, $waiting);
            }

            return $commit;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Puts a review that is kept off the pull request on record as queued: a trial
     * or a security review. Nothing is said on the pull request; the list shows it
     * from the moment it was asked for, not from when a worker takes it.
     *
     * @param  array<string, mixed>  $attributes  what is known of it already, such as the pull request and the reviewer
     */
    public function announceOwn(Repository $repository, string $branch, int $build, string $runner, array $attributes = []): void
    {
        ReviewRun::query()->create([
            ...$attributes,
            'tenant_id' => $repository->tenant_id,
            'repository_id' => $repository->getKey(),
            'workspace' => $repository->workspace,
            'slug' => $repository->slug,
            'branch' => $branch,
            // Known once the job has cloned it; the job's record takes this one over.
            'commit' => '',
            'build_number' => $build,
            'status' => ReviewRun::STATUS_QUEUED,
            'runner' => $runner,
            'custom' => true,
            'started_at' => now(),
        ]);
    }

    /**
     * Whether a build was announced and has not begun.
     */
    public function waiting(Repository $repository, int $build): bool
    {
        return $this->of($repository, $build) !== null;
    }

    /**
     * Ends a review that never began: it was told there is nothing to review, a
     * newer commit took its place, or its job died first.
     *
     * @param  string  $status  how it ended, as a status of a run
     * @param  string  $description  the same for the pull request, when its commit shows the review as in progress
     */
    public function close(Repository $repository, int $build, string $status, string $description, ?string $supersededBy = null): void
    {
        $run = $this->of($repository, $build);

        if ($run === null) {
            return;
        }

        $run->status = $status;
        $run->superseded_by = $supersededBy;
        $run->finished_at = now();
        $run->save();

        // A trial says nothing on the pull request, also when it did not start.
        if (! $run->custom && $run->pull_request_id !== null && ! $this->reviewed($repository, $run->pull_request_id, $run->commit)) {
            $this->conclusion->show($repository, $run->commit, $build, BuildState::Stopped, $description);
        }
    }

    protected function of(Repository $repository, int $build): ?ReviewRun
    {
        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->where('status', ReviewRun::STATUS_QUEUED)
            ->first();
    }

    /**
     * Whether a review of this commit of the pull request finished, so its commit
     * already says how.
     */
    protected function reviewed(Repository $repository, int $pullRequestId, string $commit): bool
    {
        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $pullRequestId)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->where('commit', 'like', substr($commit, 0, 7).'%')
            ->pluck('commit')
            ->contains(fn (string $reviewed): bool => ReviewContext::isSameCommit($reviewed, $commit));
    }
}
