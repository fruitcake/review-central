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
        // Narrowed to the user, the list is read whole and filtered here: who someone
        // is on a host is matched in PHP. Otherwise the database takes the limit.
        $records = OpenPullRequest::query()
            ->with('repository.tenant')
            ->whereIn('repository_id', $repositories->select('id'))
            ->latest('updated_at')
            ->when($only === null, fn (Builder $query) => $query->limit($limit))
            ->get();
        $attempts = $this->latestAttempts($records);

        return $records
            ->map(function (OpenPullRequest $record) use ($user, $attempts): array {
                $run = $attempts->get("{$record->repository_id}:{$record->pull_request_id}");
                $turn = PullRequestTurn::of($record, $run);

                return [
                    'record' => $record,
                    'turn' => $turn,
                    'mine' => $user->isOnHost($record->authorPerson()),
                    'my_turn' => collect($turn->people)->contains(fn (array $person): bool => $user->isOnHost($person)),
                    'run' => $run,
                ];
            })
            ->filter(fn (array $row): bool => match ($only) {
                self::MINE => $row['mine'],
                self::MY_TURN => $row['my_turn'],
                default => true,
            })
            ->take($limit)
            ->map(fn (array $row): array => array_filter([
                'repository' => $row['record']->repository->fullName(),
                'tenant' => $row['record']->repository->tenant->slug,
                'id' => $row['record']->pull_request_id,
                'title' => $row['record']->title,
                'author' => $row['record']->author,
                'branch' => $row['record']->branch,
                'draft' => $row['record']->draft ?: null,
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
     * The newest attempt at reviewing each of these pull requests, in one query,
     * by "repository id:pull request id".
     *
     * @param  Collection<int, OpenPullRequest>  $records
     * @return SupportCollection<string, ReviewRun>
     */
    protected function latestAttempts(Collection $records): SupportCollection
    {
        if ($records->isEmpty()) {
            return collect();
        }

        $runs = ReviewRun::query()
            ->select(['id', 'uuid', 'repository_id', 'pull_request_id', 'status', 'result', 'commit', 'findings', 'manual_review', 'custom', 'started_at'])
            ->whereIn('repository_id', $records->pluck('repository_id')->unique())
            ->whereIn('pull_request_id', $records->pluck('pull_request_id')->unique())
            ->whereNotIn('status', PullRequestFeedback::NO_ATTEMPT)
            ->where('custom', false)
            ->latest('started_at')
            ->get();
        $latest = [];

        // Newest first, so the first of each pull request is its newest.
        foreach ($runs as $run) {
            $latest["{$run->repository_id}:{$run->pull_request_id}"] ??= $run;
        }

        return collect($latest);
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
            ->with(['tenant', 'integration', 'latestReviewRun' => fn ($query) => $query->select(['review_runs.id', 'uuid', 'repository_id', 'pull_request_id', 'status', 'result', 'started_at'])])
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
