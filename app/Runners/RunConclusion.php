<?php

declare(strict_types=1);

namespace App\Runners;

use App\Enums\BuildState;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewHandover;
use Throwable;

/**
 * How a run this server started ends, whatever ran it: the review lock goes back,
 * a run that never reported is closed, and the pull request is told how it ended
 * through the build status on its commit.
 */
class RunConclusion
{
    /**
     * Releases the review lock, which closes a run that never reported and starts
     * the one that was waiting, and tells the pull request how this one ended.
     */
    public function conclude(Repository $repository, string $branch, string $commit, int $build, bool $reviewing, bool $trial = false): void
    {
        try {
            ReviewHandover::abandon($repository, $commit, (string) $build);

            // A trial took no lock, and releasing one would start whatever waits
            // for the real review of this branch.
            if (! $trial) {
                ReviewHandover::release($repository, $branch, $commit, (string) $build);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! $reviewing) {
            return;
        }

        $run = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('commit', $commit)
            ->where('build_number', $build)
            ->first();

        [$state, $description] = match (true) {
            $run?->status === ReviewRun::STATUS_FINISHED && $run->result === 'FAILED' => [BuildState::Failed, $run->openFindings().' finding(s) to look at'],
            $run?->status === ReviewRun::STATUS_FINISHED && $run->needsManualReview() => [BuildState::Successful, 'No findings that block; not approved, a person has to check '.count($run->manual_review ?? []).' point(s)'],
            $run?->status === ReviewRun::STATUS_FINISHED => [BuildState::Successful, 'No findings that block'],
            $run?->status === ReviewRun::STATUS_STOPPED => [BuildState::Stopped, rtrim((string) $run->summary, '.') ?: 'Stopped'],
            $run?->status === ReviewRun::STATUS_SKIPPED => [BuildState::Stopped, rtrim((string) $run->summary, '.') ?: 'Not reviewed'],
            default => [BuildState::Stopped, 'The review did not finish'],
        };

        $this->show($repository, $commit, $build, $state, $description);
    }

    /**
     * Best effort: a review that cannot show its state is still a review.
     */
    public function show(Repository $repository, string $commit, int $build, BuildState $state, string $description): void
    {
        try {
            $repository->sourceControl()->setBuildStatus(
                $commit,
                $state,
                $this->runUrl($repository, $commit, $build),
                $description,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Where the pull request's build status leads: this run, or the list of runs
     * when none was opened.
     */
    protected function runUrl(Repository $repository, string $commit, int $build): string
    {
        $run = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('commit', $commit)
            ->where('build_number', $build)
            ->first();

        return $run === null
            ? ReviewRunResource::getUrl('index', panel: 'app', tenant: $repository->tenant)
            : ReviewRunResource::getUrl('view', ['record' => $run], panel: 'app', tenant: $repository->tenant);
    }
}
