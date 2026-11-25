<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Repository;
use App\Models\ReviewRule;
use App\Services\ReviewRuleArchive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns off the rules of a repository that its main branch now holds in rule
 * files of its own, after a pull request was merged.
 */
class CheckReviewRules implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $repositoryId)
    {
        $this->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    /**
     * Queued after a merge into a repository that has rules of its own on; none
     * otherwise, there being nothing to turn off.
     */
    public static function afterMerge(Repository $repository): void
    {
        if (ReviewRule::query()->where('repository_id', $repository->getKey())->where('enabled', true)->whereNull('archived_to')->exists()) {
            self::dispatch((int) $repository->getKey());
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->repositoryId;
    }

    public function handle(ReviewRuleArchive $archive): void
    {
        $repository = Repository::query()->find($this->repositoryId);

        if ($repository !== null) {
            $archive->check($repository);
        }
    }
}
