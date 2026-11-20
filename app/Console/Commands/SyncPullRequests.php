<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OpenPullRequest;
use App\Services\ReviewRequest;
use App\SourceControl\GitHubClient;
use App\SourceControl\ReadOnlyClient;
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

    public function handle(ReviewRequest $reviews): int
    {
        $synced = 0;
        $failed = 0;

        foreach (OpenPullRequest::query()->with('repository.integration')->lazyById() as $record) {
            // A removed repository's pull requests are no one's to review here.
            if ($record->repository->trashed()) {
                continue;
            }

            try {
                $sourceControl = $record->repository->sourceControl();
                $data = $sourceControl->getPullRequest((string) $record->pull_request_id);

                // Reading the reviews is reading, also for a connection that posts nothing.
                $reads = $sourceControl instanceof ReadOnlyClient ? $sourceControl->inner() : $sourceControl;

                if ($data !== null && $reads instanceof GitHubClient) {
                    $data = GitHubClient::withReviews($data, $reads->getPullRequestReviews((string) $record->pull_request_id));
                }

                $reviews->sync($record->repository, $record->pull_request_id, $data);
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
