<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BuildState;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\RunConclusion;
use App\Runners\Security\SecurityReview;
use Illuminate\Support\Facades\Cache;

/**
 * Stops a review someone no longer wants: one that waits is closed at once; one
 * that runs is told through the cache, which its job looks at while the reviewer
 * works, and stops its sandbox. Nothing is posted on the pull request but its
 * status.
 */
class ReviewStops
{
    /** As long as a review may run, and more: a job that looks after that finds nothing. */
    protected const REMEMBERED_HOURS = 6;

    /** What can still be stopped. */
    public const STOPPABLE = [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_PENDING, ReviewRun::STATUS_RUNNING];

    public function __construct(protected RunConclusion $conclusion) {}

    /**
     * @param  string  $by  who stopped it, as the run's summary says
     * @return bool whether there was anything to stop
     */
    public function stop(ReviewRun $run, string $by): bool
    {
        if (! self::stoppable($run)) {
            return false;
        }

        Cache::put(self::key((int) $run->repository_id, (int) $run->build_number), $by, now()->addHours(self::REMEMBERED_HOURS));

        // A run that waits has no job to tell yet: closed here, and the job that
        // picks it up later finds it stopped. A pending one is then never started.
        if ($run->status !== ReviewRun::STATUS_RUNNING) {
            $this->close($run, $by);
        }

        return true;
    }

    /**
     * Whether this review can be stopped: one that waits or runs, of a kind whose
     * job looks for a stop. A branch's security review does not.
     */
    public static function stoppable(ReviewRun $run): bool
    {
        return in_array($run->status, self::STOPPABLE, true) && $run->kind !== SecurityReview::KIND;
    }

    /**
     * Who stopped this review; null when nobody did. Told by the cache while it
     * runs, and by the run itself once it is closed as stopped, so a job that
     * starts long after a stop still finds it.
     */
    public function requested(Repository $repository, int $build): ?string
    {
        $by = Cache::get(self::key((int) $repository->getKey(), $build));

        if (is_string($by)) {
            return $by;
        }

        $summary = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->where('status', ReviewRun::STATUS_STOPPED)
            ->value('summary');

        return is_string($summary) ? (preg_match('/^Stopped by (.+)\.$/', $summary, $match) === 1 ? $match[1] : 'someone') : null;
    }

    /**
     * Records the review as stopped, once its job has stopped the reviewer.
     */
    public function stopped(Repository $repository, int $build, string $by): void
    {
        $run = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->whereIn('status', self::STOPPABLE)
            ->first();

        if ($run !== null) {
            $this->close($run, $by);
        }
    }

    protected function close(ReviewRun $run, string $by): void
    {
        $run->status = ReviewRun::STATUS_STOPPED;
        $run->summary = "Stopped by {$by}.";
        $run->finished_at = now();
        $run->save();

        // A trial says nothing on the pull request.
        if (! $run->custom && $run->pull_request_id !== null) {
            $this->conclusion->show($run->repository, $run->commit, (int) $run->build_number, BuildState::Stopped, "Stopped by {$by}");
        }
    }

    protected static function key(int $repositoryId, int $build): string
    {
        return "review.stop.{$repositoryId}.{$build}";
    }
}
