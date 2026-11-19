<?php

declare(strict_types=1);

namespace App\Runners;

use App\Models\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * What a runner this server starts keeps of its runs: the number each goes by, and
 * whether it is still queued or running.
 *
 * The numbers are one series per repository, whatever runner hands them out: a
 * run's record and the review lock are both keyed on it.
 */
trait KeepsRuns
{
    /**
     * Seconds a run may take from the moment it is queued.
     */
    abstract public function lifetime(Repository $repository): int;

    public function isRunning(Repository $repository, string $build): ?bool
    {
        return Cache::has($this->heartbeat($repository, $build));
    }

    /**
     * Says a run is queued or running, for as long as it may take. A run that ends
     * says so itself; one that dies without a word stops counting when this expires.
     */
    public function markRunning(Repository $repository, int $build): void
    {
        Cache::put($this->heartbeat($repository, (string) $build), true, $this->lifetime($repository));
    }

    public function markEnded(Repository $repository, int $build): void
    {
        Cache::forget($this->heartbeat($repository, (string) $build));
    }

    protected function nextBuild(Repository $repository): int
    {
        $key = "review-container-build:{$repository->getKey()}";

        // Seeded from the clock, so a cache that was emptied does not hand out a
        // number an earlier run already has.
        Cache::add($key, time(), now()->addYears(5));

        return (int) Cache::increment($key);
    }

    protected function heartbeat(Repository $repository, string $build): string
    {
        return "review-container-run:{$repository->getKey()}:{$build}";
    }
}
