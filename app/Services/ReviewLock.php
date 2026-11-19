<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps two runs from reviewing the same pull request at once.
 *
 * A review takes minutes and only records that it happened when it posts its
 * summary, so until then nothing stops a second run — a re-run, a manual run
 * racing a trigger, or a second push — from doing the whole thing again. Worse
 * than the cost: it reads its context before the first run published, so it
 * cannot see the findings that run is about to post, and comments a second time
 * on the same defects. The lock covers exactly that window: taken when a run asks
 * for the context, released when it is done.
 *
 * A run turned away is not lost. It is recorded as pending, and the newest pending
 * run for that branch is started again once the lock is released.
 *
 * The holder is the build that took it, so a release can only be done by the run
 * that owns the lock, and a retried step can take its own lock again. The TTL is
 * the backstop for a run that dies without releasing.
 */
class ReviewLock
{
    /**
     * Longer than the review step's max-time, so it never expires under a run that
     * is still working, and short enough that a crashed run does not block the
     * next one for long.
     */
    public const TTL_SECONDS = 2700;

    public static function acquire(Repository $repository, string $branch, string $holder): bool
    {
        $key = self::key($repository, $branch);

        // add() only writes when the key is free, which is what makes this a lock
        // rather than a last-writer-wins flag.
        if (Cache::add($key, $holder, self::TTL_SECONDS)) {
            return true;
        }

        // The same build asking again is the same run continuing, not a second one.
        return Cache::get($key) === $holder;
    }

    public static function heldBy(Repository $repository, string $branch): ?string
    {
        $holder = Cache::get(self::key($repository, $branch));

        return is_string($holder) ? $holder : null;
    }

    /**
     * Takes a lock away from a run that can no longer be holding it, because that
     * build is no longer running. A stopped build is killed outright: its
     * after-script never runs and its lock would otherwise sit until it expired.
     */
    public static function takeOver(Repository $repository, string $branch, string $holder): void
    {
        Cache::put(self::key($repository, $branch), $holder, self::TTL_SECONDS);
    }

    /**
     * Releases the lock, but only for the run that holds it: a run that was turned
     * away must not free the lock of the run that turned it away.
     */
    public static function release(Repository $repository, string $branch, string $holder): bool
    {
        $key = self::key($repository, $branch);

        if (Cache::get($key) !== $holder) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    protected static function key(Repository $repository, string $branch): string
    {
        // The branch, not the pull request id: every step of every run knows
        // its branch without resolving anything, so the run that took the lock can
        // always release it — and a pull request is identified by the branch it
        // comes from, so one per branch is one per pull request.
        return 'review-lock:'.$repository->getKey().':'.sha1($branch);
    }
}
