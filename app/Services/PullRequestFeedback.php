<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RepositoryNotFound;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\RunPresenter;
use App\Models\FindingVerdict;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewQuestion;
use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A pull request as its AI review left it, as data: whose turn it is, the newest
 * review with its findings, what the team said of each, the replies in each
 * finding's thread and what was asked of the reviewer. For an agent working on
 * the pull request, and for anyone reading it outside the panel.
 */
class PullRequestFeedback
{
    /** How many earlier reviews and questions are listed. */
    public const HISTORY = 10;

    /**
     * The pull request a number or a branch names. A branch is its open pull
     * request: the one listed here, the one reviewed last, or the host's.
     *
     * @return array{id: int, branch: string|null, record: OpenPullRequest|null}
     *
     * @throws RepositoryNotFound
     */
    public function resolve(Repository $repository, ?int $pullRequestId, ?string $branch): array
    {
        $records = OpenPullRequest::query()->where('repository_id', $repository->getKey());

        if ($pullRequestId !== null) {
            $record = (clone $records)->where('pull_request_id', $pullRequestId)->first();
            $branch = $record->branch ?? ReviewRun::query()->where('repository_id', $repository->getKey())->where('pull_request_id', $pullRequestId)->latest('started_at')->value('branch');

            if ($record === null && $branch === null) {
                $branch = rescue(fn () => $repository->sourceControl()->getPullRequest((string) $pullRequestId)['source']['branch']['name'] ?? null, null, false);
            }

            if ($record === null && $branch === null) {
                throw new RepositoryNotFound("{$repository->fullName()} has no pull request #{$pullRequestId} that Review Central knows of.");
            }

            return ['id' => $pullRequestId, 'branch' => $branch, 'record' => $record];
        }

        if (blank($branch)) {
            throw new RepositoryNotFound('Name the pull request: its number, or the branch it is from.');
        }

        $record = (clone $records)->where('branch', $branch)->latest('updated_at')->first();
        $id = $record->pull_request_id
            ?? rescue(fn () => $repository->sourceControl()->findOpenPullRequestByBranch($branch)['id'] ?? null, null, false)
            ?? ReviewRun::query()->where('repository_id', $repository->getKey())->where('branch', $branch)->whereNotNull('pull_request_id')->latest('started_at')->value('pull_request_id');

        if (! is_numeric($id)) {
            throw new RepositoryNotFound("{$repository->fullName()} has no open pull request from {$branch}.");
        }

        return ['id' => (int) $id, 'branch' => $branch, 'record' => $record];
    }

    /**
     * The newest review of a pull request: the newest that was posted on it, or a
     * trial when there is nothing else.
     */
    public function latestRun(Repository $repository, int $pullRequestId): ?ReviewRun
    {
        $runs = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $pullRequestId)
            ->whereNotIn('status', [ReviewRun::STATUS_SKIPPED, ReviewRun::STATUS_RESTARTED])
            ->latest('started_at');

        return (clone $runs)->where('custom', false)->first() ?? $runs->first();
    }

    /**
     * Everything about the pull request's review.
     *
     * @param  bool  $comments  also every comment people left on it, read from the host
     * @return array<string, mixed>
     *
     * @throws RepositoryNotFound
     */
    public function of(User $user, Repository $repository, ?int $pullRequestId, ?string $branch = null, bool $comments = false): array
    {
        $pullRequest = $this->resolve($repository, $pullRequestId, $branch);
        $id = $pullRequest['id'];
        $record = $pullRequest['record'];
        $run = $this->latestRun($repository, $id);

        // One read of the host serves the threads and the comments; without it the
        // review still reads, only without the replies.
        $hostComments = null;
        $hostError = null;

        try {
            $hostComments = ReviewContext::compactComments($repository->sourceControl()->getPullRequestComments((string) $id), $run?->finished_at === null ? null : Carbon::instance($run->finished_at));
        } catch (Throwable $exception) {
            $hostError = 'The comments could not be read from '.$repository->integration->name.': '.$exception->getMessage();
        }

        return array_filter([
            'repository' => $repository->fullName(),
            'tenant' => $repository->tenant->slug,
            'pull_request' => $this->pullRequest($repository, $id, $pullRequest['branch'], $record, $run),
            'review' => $run === null ? null : $this->review($run, $record),
            'findings' => $run === null ? [] : $this->findings($run, $user, $hostComments ?? []),
            'questions' => $this->questions($repository, $id),
            'earlier_reviews' => $this->earlierReviews($repository, $id, $run),
            'comments' => $comments && $hostComments !== null ? array_values(array_filter($hostComments, fn (array $comment): bool => ! $comment['is_review'])) : null,
            'notice' => $hostError,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pullRequest(Repository $repository, int $id, ?string $branch, ?OpenPullRequest $record, ?ReviewRun $run): array
    {
        $turn = $record === null ? null : PullRequestTurn::of($record, $run);

        return array_filter([
            'id' => $id,
            'url' => rescue(fn () => $repository->sourceControl()->pullRequestUrl($id), null, false),
            'title' => $record->title ?? ($run === null ? null : RunPresenter::title($run)),
            'author' => $record?->author,
            'branch' => $branch,
            'destination_branch' => $record?->destination_branch,
            'head_commit' => $record?->commit,
            'draft' => $record?->draft,
            // Not listed: merged or declined, or never seen by the webhook.
            'open' => $record !== null,
            'reviewers' => $record === null ? null : array_map(fn (array $reviewer): array => array_filter([
                'name' => $reviewer['name'] ?? $reviewer['nickname'] ?? null,
                'state' => $reviewer['state'] ?? 'not reviewed yet',
                'at' => $reviewer['at'] ?? null,
            ]), $record->reviewers ?? []),
            'turn' => $turn === null ? null : [
                'who' => $turn->role,
                'people' => array_values(array_filter(array_map(fn (array $person): ?string => $person['name'] ?? $person['nickname'] ?? null, $turn->people))),
                'action' => $turn->action,
                'reason' => $turn->reason,
                'note' => ($record->note['key'] ?? null) === $turn->key ? ($record->note['summary'] ?? null) : null,
            ],
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function review(ReviewRun $run, ?OpenPullRequest $record = null): array
    {
        return array_filter([
            'id' => $run->uuid,
            'url' => ReviewRunResource::getUrl('view', ['record' => $run], panel: 'app', tenant: $run->repository->tenant),
            'status' => $run->status,
            'result' => $run->result,
            'commit' => $run->commit,
            // Whether it reviewed what the pull request is now.
            'current' => $record?->commit === null ? null : ReviewContext::isSameCommit($run->commit, $record->commit),
            'trial' => $run->custom ?: null,
            'reviewer' => $run->agent,
            'model' => $run->model,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'summary' => $run->summary,
            'manual_review' => $run->manual_review ?: null,
            'open_findings' => $run->openFindings(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * The findings as the reviewer wrote them, what holds the merge first, each
     * with what the team said of it and the replies in its thread.
     *
     * @param  array<int, array<string, mixed>>  $comments  as ReviewContext::compactComments() gives them
     * @return array<int, array<string, mixed>>
     */
    public function findings(ReviewRun $run, ?User $user = null, array $comments = []): array
    {
        $failOn = ReviewPublisher::failOnSpec($run->fail_on);
        $verdicts = FindingVerdict::query()->where('review_run_id', $run->getKey())->get()->groupBy('finding_id');
        $threads = $this->threads($comments);

        return collect($run->findings ?? [])
            ->map(function (array $finding) use ($failOn, $verdicts, $threads, $user): array {
                $id = (string) ($finding['id'] ?? '');
                /** @var Collection<int, FindingVerdict> $given */
                $given = $verdicts->get($id, collect());

                return array_filter([
                    ...$finding,
                    'blocks' => ReviewPublisher::blocks($finding, $failOn),
                    'open' => in_array(strtolower((string) ($finding['status'] ?? 'new')), ReviewPublisher::OPEN, true),
                    'verdicts' => $given->isEmpty() ? null : $given->countBy(fn (FindingVerdict $verdict): string => $verdict->verdict->value)->all(),
                    'your_verdict' => $user === null ? null : $given->firstWhere('user_id', $user->getKey())?->verdict->value,
                    'thread' => $threads[$id] ?? null,
                ], fn (mixed $value): bool => $value !== null);
            })
            ->sortBy(fn (array $finding): int => $finding['blocks'] ? 0 : ($finding['open'] ? 1 : 2))
            ->values()
            ->all();
    }

    /**
     * The replies under each finding's own comment, by finding id: the review's
     * own and people's, oldest first, and whether the thread is resolved.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<string, array{resolved: bool, replies: array<int, array<string, mixed>>}>
     */
    protected function threads(array $comments): array
    {
        $threads = [];
        $byParent = collect($comments)->groupBy('parent_id');

        foreach ($comments as $comment) {
            if (! $comment['is_review'] || ! preg_match_all(ReviewContext::FINDING_MARKER, (string) $comment['text'], $markers)) {
                continue;
            }

            foreach ($markers['attributes'] as $attributes) {
                if (preg_match('/(?:^|\s)id=(\S+)/', $attributes, $id) !== 1) {
                    continue;
                }

                $threads[$id[1]] = [
                    'resolved' => (bool) $comment['resolved'],
                    'replies' => $byParent->get($comment['id'], collect())->map(fn (array $reply): array => [
                        'author' => $reply['author'],
                        'by_review' => $reply['is_review'],
                        'at' => $reply['created_on'],
                        'after_last_review' => $reply['after_last_review'],
                        'text' => $reply['text'],
                    ])->values()->all(),
                ];
            }
        }

        return $threads;
    }

    /**
     * What people asked the reviewer on the pull request, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function questions(Repository $repository, int $id): array
    {
        return ReviewQuestion::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $id)
            ->where('status', '!=', ReviewQuestion::STATUS_IGNORED)
            ->latest()
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (ReviewQuestion $question): array => self::question($question))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function question(ReviewQuestion $question): array
    {
        return array_filter([
            'id' => $question->getKey(),
            // A question on the review's page names its user rather than an author.
            'asked_by' => $question->author ?? ($question->user_id === null ? null : User::query()->whereKey($question->user_id)->value('name')),
            'asked_at' => $question->created_at->toIso8601String(),
            'where' => $question->comment_id === null ? 'review page' : 'pull request',
            'about' => $question->about,
            'question' => $question->question,
            'status' => $question->status,
            'answer' => $question->answer,
            'answered_at' => $question->answered_at?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function earlierReviews(Repository $repository, int $id, ?ReviewRun $latest): array
    {
        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $id)
            ->when($latest !== null, fn ($query) => $query->whereKeyNot($latest->getKey()))
            ->latest('started_at')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (ReviewRun $run): array => array_filter([
                'id' => $run->uuid,
                'status' => $run->status,
                'result' => $run->result,
                'commit' => $run->commit,
                'trial' => $run->custom ?: null,
                'started_at' => $run->started_at->toIso8601String(),
                'open_findings' => $run->openFindings(),
            ], fn (mixed $value): bool => $value !== null))
            ->all();
    }
}
