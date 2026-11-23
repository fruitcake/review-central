<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\SourceControl\GitHubClient;
use App\SourceControl\ReadOnlyClient;
use App\SourceControl\SourceControl;

/**
 * Reads pull requests from their host and lists them as a delivery would, without
 * starting a review: for one whose delivery was missed, and for a repository whose
 * open pull requests are read in at once.
 */
class PullRequestSync
{
    public function __construct(protected ReviewRequest $reviews) {}

    /**
     * Reads one listed pull request again: gone from the list when it is no longer open.
     */
    public function one(OpenPullRequest $record): void
    {
        $host = $record->repository->sourceControl();

        $this->reviews->sync($record->repository, $record->pull_request_id, $this->withReviews($host, $host->getPullRequest((string) $record->pull_request_id)));
    }

    /**
     * Lists every open pull request of the repository as the host has it, and reads
     * those listed here that it no longer lists open again, so a merged or declined
     * one leaves the list.
     *
     * @return array{listed: int, closed: int}
     */
    public function repository(Repository $repository): array
    {
        $host = $repository->sourceControl();
        $open = [];

        foreach ($host->openPullRequests() as $pullRequest) {
            if (is_numeric($pullRequest['id'] ?? null)) {
                $open[(int) $pullRequest['id']] = true;
                $this->reviews->sync($repository, (int) $pullRequest['id'], $this->withReviews($host, $pullRequest));
            }
        }

        $gone = OpenPullRequest::query()
            ->where('repository_id', $repository->getKey())
            ->whereNotIn('pull_request_id', array_keys($open))
            ->get();

        foreach ($gone as $record) {
            $record->setRelation('repository', $repository);
            $this->one($record);
        }

        return ['listed' => count($open), 'closed' => $gone->count()];
    }

    /**
     * GitHub says who reviewed only when asked apart; reading that is reading,
     * also for a connection that posts nothing.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    protected function withReviews(SourceControl $host, ?array $data): ?array
    {
        $reads = $host instanceof ReadOnlyClient ? $host->inner() : $host;

        return $data !== null && $reads instanceof GitHubClient
            ? GitHubClient::withReviews($data, $reads->getPullRequestReviews((string) $data['id']))
            : $data;
    }
}
