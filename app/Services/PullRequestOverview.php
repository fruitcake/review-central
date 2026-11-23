<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The open pull requests of a user's repositories, each with whose turn it is,
 * as data: the overview's list, for whatever reads as the user outside the panel.
 */
class PullRequestOverview
{
    /** Open pull requests read at a time while a list is filled. */
    public const CHUNK = 100;

    /** Who a list can be narrowed to. */
    public const MINE = 'mine';

    public const MY_TURN = 'my-turn';

    /**
     * @param  Builder<Repository>  $repositories
     * @param  string|null  $only  MINE for those the user opened, MY_TURN for those waiting for them
     * @return array<int, array<string, mixed>>
     */
    public function list(User $user, Builder $repositories, ?string $only = null, int $limit = 50): array
    {
        $rows = collect();

        // Newest first, a chunk at a time, until the limit is reached: who someone is
        // on a host is matched here, not in the database.
        OpenPullRequest::query()
            ->with('repository.tenant')
            ->whereIn('repository_id', $repositories->select('id'))
            ->latest('updated_at')
            ->latest('id')
            ->chunk(min(max($limit, 20), self::CHUNK), function (Collection $records) use ($user, $only, $limit, $rows): bool {
                $attempts = $this->latestAttempts($records);

                foreach ($records as $record) {
                    $run = $attempts->get("{$record->repository_id}:{$record->pull_request_id}");
                    $turn = PullRequestTurn::of($record, $run, lookUp: false);
                    $row = ['record' => $record, 'turn' => $turn, 'mine' => $user->isOnHost($record->authorPerson()), 'my_turn' => $turn->isFor($user), 'run' => $run];

                    if (match ($only) {
                        self::MINE => $row['mine'],
                        self::MY_TURN => $row['my_turn'],
                        default => true,
                    }) {
                        $rows->push($row);
                    }

                    if ($rows->count() >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $rows
            ->map(fn (array $row): array => array_filter([
                'repository' => $row['record']->repository->fullName(),
                'tenant' => $row['record']->repository->tenant->slug,
                'id' => $row['record']->pull_request_id,
                'title' => $row['record']->title,
                'author' => $row['record']->author,
                'branch' => $row['record']->branch,
                'draft' => $row['record']->draft ?: null,
                // As last asked, by the nightly sync or a read of its feedback.
                'conflicts' => $row['record']->merge_conflicts,
                'updated_at' => $row['record']->updated_at?->toIso8601String(),
                'mine' => $row['mine'],
                'turn' => [
                    'who' => $row['turn']->role,
                    'people' => array_values(array_filter(array_map(fn (array $person): ?string => $person['name'] ?? $person['nickname'] ?? null, $row['turn']->people))),
                    'action' => $row['turn']->action,
                    'reason' => $row['turn']->reason,
                    'yours' => $row['my_turn'],
                ],
                'review' => $row['run'] === null ? null : array_filter([
                    'id' => $row['run']->uuid,
                    'status' => $row['run']->status,
                    'result' => $row['run']->result,
                    'open_findings' => $row['run']->openFindings(),
                ], fn (mixed $value): bool => $value !== null),
            ], fn (mixed $value): bool => $value !== null))
            ->values()
            ->all();
    }

    /**
     * The newest attempt at reviewing each of these pull requests, by "repository
     * id:pull request id". The history is read without its findings to pick the
     * newest of each, and only those are read whole.
     *
     * @param  Collection<int, OpenPullRequest>  $records
     * @return SupportCollection<string, ReviewRun>
     */
    protected function latestAttempts(Collection $records): SupportCollection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $history = ReviewRun::query()
            ->select(['id', 'repository_id', 'pull_request_id'])
            // These pull requests exactly, not every pairing of their repositories and numbers.
            ->where(function (Builder $query) use ($records): void {
                foreach ($records as $record) {
                    $query->orWhere(fn (Builder $pair) => $pair->where('repository_id', $record->repository_id)->where('pull_request_id', $record->pull_request_id));
                }
            })
            ->whereNotIn('status', PullRequestFeedback::NO_ATTEMPT)
            ->where('custom', false)
            ->latest('started_at')
            ->toBase()
            ->get();
        $newest = [];

        // Newest first, so the first of each pull request is its newest.
        foreach ($history as $run) {
            $newest["{$run->repository_id}:{$run->pull_request_id}"] ??= (int) $run->id;
        }

        $runs = ReviewRun::query()
            ->select(['id', 'uuid', 'repository_id', 'pull_request_id', 'status', 'result', 'commit', 'findings', 'manual_review', 'custom', 'started_at'])
            ->whereKey(array_values($newest))
            ->get()
            ->keyBy('id');

        return collect($newest)->map(fn (int $id): ?ReviewRun => $runs->get($id))->filter();
    }

    /**
     * The user's repositories, each with how its reviews are set up and its last.
     *
     * @param  Builder<Repository>  $repositories
     * @return array<int, array<string, mixed>>
     */
    public function repositories(Builder $repositories): array
    {
        return $repositories
            ->with(['tenant', 'integration', 'latestReviewRun' => fn ($query) => $query->select(['review_runs.id', 'review_runs.uuid', 'review_runs.repository_id', 'review_runs.pull_request_id', 'review_runs.status', 'review_runs.result', 'review_runs.started_at'])])
            ->withCount('openPullRequests')
            ->orderBy('workspace')
            ->orderBy('slug')
            ->get()
            ->map(function (Repository $repository): array {
                $last = $repository->latestReviewRun;

                return array_filter([
                    'repository' => $repository->fullName(),
                    'tenant' => $repository->tenant->slug,
                    'host' => $repository->integration->name,
                    'connection' => $repository->connection_id,
                    'reviews_enabled' => $repository->reviews_enabled,
                    'open_pull_requests' => $repository->open_pull_requests_count,
                    'last_review' => $last === null ? null : array_filter([
                        'id' => $last->uuid,
                        'pull_request_id' => $last->pull_request_id,
                        'status' => $last->status,
                        'result' => $last->result,
                        'started_at' => $last->started_at->toIso8601String(),
                    ], fn (mixed $value): bool => $value !== null),
                ], fn (mixed $value): bool => $value !== null);
            })
            ->all();
    }
}
