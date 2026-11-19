<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewRun;
use Throwable;

/**
 * Hands the review lock back and starts whatever was waiting for it.
 *
 * Called from two places on purpose: when the result is reported, and again when
 * the run's job ends. The first is the one that matters — the review is published
 * by then, so the waiting run can start immediately. The second is the safety net
 * for a run that never reports, and finds nothing to do when the first already ran.
 *
 * That works because a lock is only ever released to the build that holds it, so
 * the second call is a no-op rather than a second handover.
 */
class ReviewHandover
{
    /**
     * @return array<string, mixed>
     */
    public static function release(Repository $repository, string $branch, string $commit, string $holder): array
    {
        $released = ReviewLock::release($repository, $branch, $holder);

        return [
            'released' => $released,
            'locked_by' => ReviewLock::heldBy($repository, $branch),
            // Only the run that actually held the lock hands over, so a run that was
            // itself turned away cannot start anything.
            'restarted' => $released ? self::restartNewestPending($repository, $branch, $commit) : null,
        ];
    }

    /**
     * Closes the run of a build that ended without reporting. A run still `running`
     * when its job ends never will report, and the pull request would show its
     * review as running for good.
     */
    public static function abandon(Repository $repository, string $commit, string $holder): void
    {
        if (! ctype_digit($holder)) {
            return;
        }

        $runs = ReviewRun::running()
            ->where('repository_id', $repository->getKey())
            ->where('commit', $commit)
            ->where('build_number', (int) $holder)
            ->get();

        foreach ($runs as $run) {
            $run->finish(ReviewRun::STATUS_FAILED);
        }
    }

    /**
     * Starts the newest run this branch had waiting, and only that one.
     *
     * Several pushes can pile up behind one review. Only the last is worth running:
     * it is the pull request as it now stands, and the ones before it are already
     * inside its diff. The rest are closed as superseded so nothing starts them.
     *
     * Restarting is recorded before the call, so a run is started at most once and
     * a handover can never become a loop.
     *
     * @return array<string, mixed>|null
     */
    protected static function restartNewestPending(Repository $repository, string $branch, string $justReviewed): ?array
    {
        $pending = ReviewRun::query()
            ->awaitingRestart()
            ->where('repository_id', $repository->getKey())
            ->where('branch', $branch)
            ->where('commit', '!=', $justReviewed)
            ->orderByDesc('started_at')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $newest = $pending->shift();

        // Overtaken, not failed: nothing went wrong with these, a newer commit was
        // waiting too and reviewing that one covers them.
        foreach ($pending as $older) {
            $older->status = ReviewRun::STATUS_SKIPPED;
            $older->superseded_by = $newest->commit;
            $older->restarted_at = now();
            $older->save();
        }

        // Stamped before the call, so a throw cannot have it tried again — and the
        // status moves off pending, because a pending row that has already been
        // handed over reads as still waiting for one.
        $newest->status = ReviewRun::STATUS_RESTARTED;
        $newest->restarted_at = now();
        $newest->save();

        try {
            $build = $repository->runner()->start($repository, $branch, $newest->commit);

            // The run that replaces this one is a different build with its own
            // record; this is what ties the two halves of the handover together.
            $newest->restarted_as = $build;
            $newest->save();

            return ['commit' => $newest->commit, 'started' => true, 'build_number' => $build];
        } catch (Throwable $exception) {
            report($exception);

            return ['commit' => $newest->commit, 'started' => false, 'reason' => $exception->getMessage()];
        }
    }
}
