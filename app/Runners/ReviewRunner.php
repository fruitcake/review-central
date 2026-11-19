<?php

declare(strict_types=1);

namespace App\Runners;

use App\Models\Repository;

/**
 * A system a review runs on: a container on this installation's worker, or
 * whatever comes next. A repository chooses one.
 *
 * Whichever it is, this server starts it when a pull request gets a new commit:
 * no runner has a trigger of its own.
 */
interface ReviewRunner
{
    public function label(): string;

    /**
     * One word for a table or a tag.
     */
    public function shortLabel(): string;

    public function description(): string;

    /**
     * What to do when it is not set up, in words for the person who has to.
     */
    public function setupHint(): string;

    /**
     * Whether this installation is set up to run it.
     */
    public function available(): bool;

    /**
     * Starts a review of a branch.
     *
     * @param  string|null  $commit  the commit to review; null for the branch as it is now
     * @return int|null the number the run goes by, which holds the review lock
     *
     * @throws \Throwable when the run could not be started
     */
    public function start(Repository $repository, string $branch, ?string $commit = null): ?int;

    /**
     * Whether the run that holds a lock may still release it.
     *
     * @return bool|null null when that cannot be told, which keeps the lock
     */
    public function isRunning(Repository $repository, string $build): ?bool;
}
