<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PullRequestDraft;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\ReviewSessions;
use App\SourceControl\WebhookEvent;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * on is left to a person.
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
        if (! $this->runsTheReview($repository)) {
            return;
        }

        match ($event->type) {
            WebhookEvent::COMMENT_CREATED => $this->handleComment($repository, $event),
            WebhookEvent::PULL_REQUEST_CHANGED => $this->handlePullRequest($repository, $event->pullRequest),
            default => null,
        };
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

        $this->start($repository, $pullRequest['source']['branch']['name'] ?? null);
    }

    /**
     * Keeps whether the pull request is a draft, and starts the review when it was
     * one and is not any more, unless its head is reviewed or being reviewed. Marked
     * ready again without new commits, it has nothing new to review.
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
            PullRequestDraft::query()->where($key)->delete();

            // Merged or declined: what the reviewer kept of it has no further use.
            if (is_string($data['source']['branch']['name'] ?? null)) {
                app(ReviewSessions::class)->forget($repository, $data['source']['branch']['name']);
            }

            return;
        }

        $updatedOn = self::timestamp($data['updated_on'] ?? null);

        // Created outside the transaction, so a second delivery racing to create the
        // same row finds it instead of failing on the unique key.
        PullRequestDraft::query()->createOrFirst($key, ['draft' => $data['draft'], 'source_updated_on' => $updatedOn]);

        $markedReady = DB::transaction(function () use ($key, $data, $updatedOn): bool {
            $record = PullRequestDraft::query()->where($key)->lockForUpdate()->firstOrFail();

            // Deliveries arrive out of order, so an older snapshot says nothing.
            if ($updatedOn !== null && $record->source_updated_on !== null && $updatedOn < $record->source_updated_on) {
                return false;
            }

            $wasDraft = $record->draft;
            $record->draft = $data['draft'];
            $record->source_updated_on = $updatedOn ?? $record->source_updated_on;
            $record->save();

            return $wasDraft && ! $record->draft;
        });

        $commit = $data['source']['commit']['hash'] ?? null;

        // Nothing else starts a review: every new head of a pull request that is
        // not a draft is started here. The review's own edit of the description
        // arrives as an update too, with a head that is reviewed by then.
        $wanted = $markedReady || ! $data['draft'];

        if (! $wanted || ! is_string($commit) || $commit === '' || $this->isReviewing($repository, (int) $data['id'], $commit)) {
            return;
        }

        // A host sends more than one event for one push, and the first has no run
        // on record yet when the second arrives.
        if (Cache::add("review-start:{$repository->getKey()}:{$data['id']}:{$commit}", true, self::SAME_PUSH_SECONDS)) {
            $this->start($repository, $data['source']['branch']['name'] ?? null);
        }
    }

    /**
     * Starts the review on the branch of an open pull request, on the runner the
     * repository chose.
     *
     * @param  bool  $strict  throw when the repository's runner is not set up, for a person who asked in the panel
     *
     * @throws RuntimeException when $strict and the runner is not set up
     * @throws \Throwable when the runner could not start it
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
