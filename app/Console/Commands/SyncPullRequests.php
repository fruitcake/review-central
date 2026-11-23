<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OpenPullRequest;
use App\Services\PullRequestSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reads every listed pull request from its host again: its reviewers and what
 * they did, for those listed before their turn was kept, and one whose delivery
 * was missed. One that is merged or declined by now leaves the list.
 */
class SyncPullRequests extends Command
{
    protected $signature = 'review:sync-pull-requests';

    protected $description = 'Read the open pull requests\' reviewers and state from their hosts again';

    public function handle(PullRequestSync $sync): int
    {
        $synced = 0;
        $failed = 0;

        foreach (OpenPullRequest::query()->with('repository.integration')->lazyById() as $record) {
            // A removed repository's pull requests are no one's to review here.
            if ($record->repository->trashed()) {
                continue;
            }

            try {
                $sync->one($record);
                $synced++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("{$record->repository->slug} #{$record->pull_request_id}: {$exception->getMessage()}");
            }
        }

        $this->info("Read {$synced} pull request(s) again".($failed > 0 ? ", {$failed} could not be read." : '.'));

        return self::SUCCESS;
    }
}
