<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OpenFinding;
use App\Models\Repository;
use App\Services\OpenFindings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a repository's open findings against its main branch: the ones asked
 * for, or those due by the team's interval.
 */
class CheckOpenFindings implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  array<int, int>|null  $ids  the findings to check now; null for those that are due
     */
    public function __construct(public int $repositoryId, public ?array $ids = null)
    {
        // The reviews' queue, whose workers wait long enough for a request per file.
        $this->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    public function handle(OpenFindings $findings): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $due = $this->ids === null
            ? OpenFindings::due($repository)
            : OpenFinding::query()->where('repository_id', $repository->getKey())->active()->whereKey($this->ids)->get();

        if ($due->isNotEmpty()) {
            $findings->check($repository, $due);
        }
    }
}
