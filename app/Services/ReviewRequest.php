<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BuildState;
use App\Enums\ReviewCommandAccess;
use App\Enums\ReviewsFrom;
use App\Filament\App\Resources\PullRequests\PullRequestResource;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Runners\Container\ReviewSessions;
use App\SourceControl\PullRef;
use App\SourceControl\WebhookEvent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Starts the AI review from the connection's webhook: for every new head of a pull
 * request that is not a draft, for a draft marked ready, and for a `/review`
 * comment. A comment that starts with `@review` is a question for the reviewer,
 * which {@see ReviewQuestions} takes.
 *
 * A `/review` comment needs no override in the precheck: it is itself something the
 * last review has not seen, and that is what lets a run on a commit that was already
 * reviewed through, a draft too. Text after the command reaches the review the same
 * way, as a comment to weigh, never as an instruction for how to review.
 *
 * Only for a repository whose pull requests are reviewed: one added to try a review
 * on is left to a person. And only from someone the tenant lets start one by
 * comment ({@see Tenant::allowsReviewCommandFrom()}); anyone else is told so once,
 * in a reply under their comment.
 *
 * A pull request from a fork is reviewed only when the tenant turned that on, and
 * only where the host keeps its head in the repository itself ({@see PullRef}):
 * its code is anyone's, and it runs with the tenant's AI key and on its budget.
 *
 * A review that is already running is left alone: the run started here is turned
 * away by the review lock and waits for it like any other.
 */
class ReviewRequest
{
    public const COMMAND = '/review';

    /**
     * How long a second event for the same head of a pull request counts as the
     * same push.
     */
    protected const SAME_PUSH_SECONDS = 120;

    /**
     * A run in one of these on a commit means that commit is reviewed, or will be.
     */
    protected const REVIEWING = [
        ReviewRun::STATUS_QUEUED,
        ReviewRun::STATUS_PENDING,
        ReviewRun::STATUS_RUNNING,
        ReviewRun::STATUS_FINISHED,
    ];

    public static function isRequest(string $comment): bool
    {
        return preg_match('~^'.preg_quote(self::COMMAND, '~').'(?:\s|$)~', ltrim($comment)) === 1;
    }

    /**
     * The comment that asks for a review, with what the person wants it to know.
     */
    public static function comment(?string $note): string
    {
        $note = trim((string) $note);

        return $note === '' ? self::COMMAND : self::COMMAND."\n\n".$note;
    }

    /**
     * @throws RequestException when the host refuses to start it
     */
    public function handle(Repository $repository, WebhookEvent $event): void
    {
        // Every pull request is kept, reviewed by itself or not: the overview lists
        // them, for a person to start the ones that wait.
        if ($event->type === WebhookEvent::PULL_REQUEST_CHANGED) {
            $this->handlePullRequest($repository, $event->pullRequest);

            return;
        }

        if ($event->type === WebhookEvent::COMMENT_CREATED && $this->runsTheReview($repository)) {
            $this->handleComment($repository, $event);
        }
    }

    protected function handleComment(Repository $repository, WebhookEvent $event): void
    {
        $questions = app(ReviewQuestions::class);

        // The command first: under one of the reviewer's answers it is still a command.
        // Anything else may be a question: asked by name, or a reply under one of
        // the reviewer's answers or findings. The question service decides.
        if (! self::isRequest((string) $event->comment)) {
            $questions->ask($repository, $event);

            return;
        }

        $pullRequest = $event->pullRequest;

        if (($pullRequest['state'] ?? null) !== 'OPEN') {
            return;
        }

        if (! $repository->tenant->allowsReviewCommandFrom($event->commentAuthorId, $event->commentAuthorNickname)) {
            $this->refuse($repository, $event, match ($repository->tenant->review_command) {
                ReviewCommandAccess::Nobody => 'A review is not started by comment here.',
                default => 'Only the accounts this team has listed in Review Central can start a review by comment.',
            }.' A review still starts on every push to the pull request.');

            return;
        }

        // Where only the trusted accounts' pull requests are reviewed by themselves,
        // only they start one by comment too, whoever else may comment /review.
        if ($repository->reviews_from === ReviewsFrom::Trusted
            && ! $repository->tenant->trusts($event->commentAuthorId, $event->commentAuthorNickname)) {
            $this->refuse($repository, $event, 'On this repository only the accounts this team trusts start a review. Someone in the team can start it from Review Central.');

            return;
        }

        $fork = self::forkRefusal($repository, $pullRequest);

        if ($fork !== null) {
            $this->refuse($repository, $event, $fork);

            return;
        }

        $this->start($repository, $pullRequest['source']['branch']['name'] ?? null);
    }

    /**
     * Why a pull request from a fork is not reviewed by itself, or null when it is
     * not from one, or may be.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    public static function forkRefusal(Repository $repository, array $pullRequest): ?string
    {
        if (! self::reviewable($pullRequest)) {
            return 'This pull request comes from a fork, and its host keeps no copy of its branch in this repository, so it cannot be reviewed here.';
        }

        return ($pullRequest['from_fork'] ?? false) === true && ! $repository->tenant->review_forks
            ? 'Pull requests from forks are not reviewed here by themselves: their code would run with this team\'s AI key and on its budget. Someone in the team can start one from Review Central, or turn it on under Review settings.'
            : null;
    }

    /**
     * Whether the pull request's code can be reviewed from its branch in this
     * repository: any but a fork's whose host keeps no copy of it here.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    public static function reviewable(array $pullRequest): bool
    {
        return ($pullRequest['from_fork'] ?? false) !== true
            || PullRef::id((string) ($pullRequest['source']['branch']['name'] ?? '')) !== null;
    }

    /**
     * Says under a comment why it started nothing: once, however often the host
     * delivers it.
     */
    protected function refuse(Repository $repository, WebhookEvent $event, string $reason): void
    {
        $pullRequestId = $event->pullRequest['id'] ?? null;

        if ($event->commentId === null || ! is_numeric($pullRequestId)
            || ! Cache::add("review-command-refused:{$repository->getKey()}:{$event->commentId}", true, now()->addDay())) {
            return;
        }

        app(ReviewQuestions::class)->reply($repository, (int) $pullRequestId, $event->commentId, $reason);
    }

    /**
     * Keeps the open pull request for the overview, and starts the review of a new
     * head, or of a draft marked ready, unless its head is reviewed or being
     * reviewed. Marked ready again without new commits, it has nothing new to
     * review. One the repository does not review by itself waits for a person.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handlePullRequest(Repository $repository, array $data): void
    {
        if (! isset($data['id']) || ! is_bool($data['draft'] ?? null)) {
            return;
        }

        $key = ['repository_id' => $repository->getKey(), 'pull_request_id' => (int) $data['id']];

        if (($data['state'] ?? null) !== 'OPEN') {
            OpenPullRequest::query()->where($key)->delete();

            // Merged or declined: what the reviewer kept of it has no further use.
            if (is_string($data['source']['branch']['name'] ?? null)) {
                app(ReviewSessions::class)->forget($repository, $data['source']['branch']['name']);
            }

            return;
        }

        $updatedOn = self::timestamp($data['updated_on'] ?? null);

        // Created outside the transaction, so a second delivery racing to create the
        // same row finds it instead of failing on the unique key.
        OpenPullRequest::query()->createOrFirst($key, ['draft' => $data['draft'], 'source_updated_on' => $updatedOn]);

        $commit = $data['source']['commit']['hash'] ?? null;

        $markedReady = DB::transaction(function () use ($key, $data, $updatedOn, $commit): bool {
            $record = OpenPullRequest::query()->where($key)->lockForUpdate()->firstOrFail();

            // Deliveries arrive out of order, so an older snapshot says nothing.
            if ($updatedOn !== null && $record->source_updated_on !== null && $updatedOn < $record->source_updated_on) {
                return false;
            }

            $wasDraft = $record->draft;
            $record->forceFill([
                'draft' => $data['draft'],
                'source_updated_on' => $updatedOn ?? $record->source_updated_on,
                'title' => self::text($data['title'] ?? null),
                'author' => self::text($data['author']['display_name'] ?? null),
                'author_id' => self::text($data['author']['account_id'] ?? null),
                'author_nickname' => self::text($data['author']['nickname'] ?? null),
                'branch' => self::text($data['source']['branch']['name'] ?? null),
                'from_fork' => ($data['from_fork'] ?? false) === true,
            ]);

            // A new head waits afresh, if it waits at all: decided below.
            if (is_string($commit) && $commit !== '' && $record->commit !== $commit) {
                $record->commit = $commit;
                $record->awaiting_approval = false;
            }

            $record->save();

            return $wasDraft && ! $record->draft;
        });

        // Nothing else starts a review: every new head of a pull request that is
        // not a draft is started here. The review's own edit of the description
        // arrives as an update too, with a head that is reviewed by then.
        $wanted = $markedReady || ! $data['draft'];

        if (! $wanted || ! is_string($commit) || $commit === '' || $this->isReviewing($repository, (int) $data['id'], $commit)) {
            return;
        }

        // Turned off, or added to try a review on: listed, and left to a person.
        if (! $this->runsTheReview($repository)) {
            return;
        }

        // A fork whose branch its host keeps nowhere in this repository, as on
        // Bitbucket, cannot be reviewed from it at all: there is nothing to wait for.
        if (! self::reviewable($data)) {
            return;
        }

        // A fork's pull request that may not be reviewed by itself, or one by an
        // account the team does not trust where only theirs are: it waits for
        // someone in the team, and says so on the pull request, once per head.
        if (self::forkRefusal($repository, $data) !== null || ! $this->reviewsAuthorOf($repository, $data)) {
            if (Cache::add("review-await:{$repository->getKey()}:{$data['id']}:{$commit}", true, self::SAME_PUSH_SECONDS)) {
                $this->await($repository, $key, $commit);
            }

            return;
        }

        // A host sends more than one event for one push, and the first has no run
        // on record yet when the second arrives.
        if (! Cache::add("review-start:{$repository->getKey()}:{$data['id']}:{$commit}", true, self::SAME_PUSH_SECONDS)) {
            return;
        }

        $this->start($repository, $data['source']['branch']['name'] ?? null);
    }

    /**
     * Whether the repository reviews this pull request's author's work by itself.
     *
     * @param  array<string, mixed>  $data
     */
    protected function reviewsAuthorOf(Repository $repository, array $data): bool
    {
        return $repository->reviews_from === ReviewsFrom::Everyone
            || $repository->tenant->trusts(self::text($data['author']['account_id'] ?? null), self::text($data['author']['nickname'] ?? null));
    }

    /**
     * Marks the pull request as waiting for someone to start its review, and shows
     * that on its head as the review's build status, which leads to the overview.
     * The review's own status replaces it once it starts.
     *
     * @param  array{repository_id: mixed, pull_request_id: int}  $key
     */
    protected function await(Repository $repository, array $key, string $commit): void
    {
        OpenPullRequest::query()->where($key)->where('commit', $commit)->update(['awaiting_approval' => true]);

        try {
            $repository->sourceControl()->setBuildStatus(
                $commit,
                BuildState::InProgress,
                PullRequestResource::getUrl('index', panel: 'app', tenant: $repository->tenant),
                'Waiting for someone in the team to start the review in Review Central',
            );
        } catch (Throwable $exception) {
            // Best effort: the pull request is listed either way.
            report($exception);
        }
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Starts the review on the branch of an open pull request, on the runner the
     * repository chose.
     *
     * @param  bool  $strict  throw when the repository's runner is not set up, for a person who asked in the panel
     *
     * @throws RuntimeException when $strict and the runner is not set up
     * @throws Throwable when the runner could not start it
     */
    public function start(Repository $repository, mixed $branch, bool $strict = false): void
    {
        if (! is_string($branch) || $branch === '') {
            return;
        }

        $runner = $repository->runner();

        // Said at once to a person who asked. A webhook's review goes ahead, and
        // ends as one that did not finish, with the reason in its log.
        if ($strict && ! $runner->available()) {
            throw new RuntimeException("{$repository->fullName()} reviews on {$runner->label()}, which is not set up on this installation. {$runner->setupHint()} Or pick another runner under \"Runs on\".");
        }

        // Not pinned to a commit: the delivery only carries an abbreviated hash, and
        // the branch as it is now is what the person asked to have reviewed.
        $repository->runner()->start($repository, $branch);

        // Started, by a person or by itself: none of its pull requests waits any more.
        OpenPullRequest::query()->where('repository_id', $repository->getKey())->where('branch', $branch)->update(['awaiting_approval' => false]);
    }

    /**
     * The host's timestamp as a UTC string to the microsecond, which sorts as it
     * reads: two updates within one second still come in the right order.
     */
    protected static function timestamp(mixed $time): ?string
    {
        return is_string($time) ? Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s.u') : null;
    }

    protected function runsTheReview(Repository $repository): bool
    {
        // Added to try a review on, or turned off: only a person starts one.
        return $repository->reviews_enabled;
    }

    /**
     * Whether a review of `$commit` in this pull request finished, runs or waits for
     * the lock. A custom run is not published, so it does not count.
     */
    protected function isReviewing(Repository $repository, int $pullRequestId, string $commit): bool
    {
        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $pullRequestId)
            ->where('custom', false)
            ->whereIn('status', self::REVIEWING)
            ->where('commit', 'like', substr($commit, 0, 7).'%')
            ->pluck('commit')
            ->contains(fn (string $runCommit): bool => ReviewContext::isSameCommit($runCommit, $commit));
    }
}
