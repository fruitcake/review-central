<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts a review that has already been decided onto its pull request.
 *
 * This makes no judgement about the findings. Something else read the code and
 * settled what is a finding, how severe it is and whether it is still open; all of
 * that arrives in the report. The job here is to make the pull request show it:
 * post what is new, edit what changed, reply where a status moved, and leave the
 * rest alone.
 *
 * It used to be a language model following a prompt. Every decision it made was
 * mechanical — match a marker, diff a body, pick a template — so the prompt spent
 * most of its length telling the model not to reword, drop or re-judge anything.
 * Code cannot do those things in the first place, and it can be tested.
 *
 * The markers are the state. {@see ReviewContext} reads them back on the next run,
 * so every rule about them there applies here in reverse.
 */
class ReviewPublisher
{
    /**
     * Severities, worst first. Anything the review invents beyond these sorts last
     * rather than being dropped — a finding nobody can see is worse than one in an
     * odd order.
     *
     * @var array<int, string>
     */
    public const SEVERITIES = ['HIGH', 'MEDIUM', 'LOW'];

    /**
     * What kind of defect a finding is. `correctness` is the default and is left
     * unsaid: it is what a review is presumed to be about, and printing it on every
     * finding would be noise that hides the ones worth flagging.
     *
     * @var array<int, string>
     */
    public const CATEGORIES = ['correctness', 'security', 'performance', 'maintainability', 'tests'];

    /**
     * The confidence at and above which a finding is treated as established.
     *
     * Below it, the review is saying "this looks wrong and I could not finish proving
     * it" — worth a reader's minute, not worth failing a build or a merge over. The
     * same number gates the result ({@see self::blocks()}).
     */
    public const CONFIDENT = 8;

    /**
     * What fences the review's own part of the pull request description.
     *
     * Everything between them belongs to this review and is replaced wholesale on
     * every run; everything outside belongs to whoever wrote the description and is
     * never touched. They are Markdown link-reference definitions, so Bitbucket does
     * not render them and nobody has to look at them.
     */
    public const DESCRIPTION_START = '[//]: # (ai-review-start)';

    public const DESCRIPTION_END = '[//]: # (ai-review-end)';

    /**
     * What goes above the block when the pull request had no description at all.
     *
     * Without it the whole description is the review's own fenced section, and there
     * is no obvious place left for a person to write: everything inside the fence is
     * replaced on the next run. This is a line above the fence that is theirs, and
     * that the review never touches again once it has been written once.
     */
    public const DESCRIPTION_PLACEHOLDER = '_Add a description here. The review keeps to its own section below._';

    /**
     * The review's block within a description, fenced by the two markers above.
     * Tolerant of whitespace so a hand-edit between runs does not orphan it.
     */
    public const DESCRIPTION_BLOCK = ReviewContext::DESCRIPTION_BLOCK;

    /**
     * Ends the review's own replies in a finding's thread, so the next run can tell
     * them from a person's. {@see ReviewContext::REPLY_MARKER}.
     */
    public const REPLY_MARKER = '[//]: # (ai-review-reply)';

    /**
     * How each status reads in the findings table.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'new' => '🆕 New',
        'open' => '⏳ Open',
        'fixed' => '✅ Fixed',
        'acknowledged' => '💬 Acknowledged',
        'false_positive' => '🚫 False positive',
        'outdated' => '🗑️ Outdated',
        'unreported' => '❔ Not reported again',
    ];

    /**
     * Statuses that still ask for work, and so can hold the merge.
     *
     * `unreported` is deliberately not one: the review no longer reports the
     * finding while the code it was about is unchanged, so it may have been fixed
     * somewhere else. Its thread stays open for a person to settle, but it blocks
     * nothing on a claim the review itself has stopped making.
     *
     * @var array<int, string>
     */
    public const OPEN = ['new', 'open'];

    /**
     * What `fail_on` means when the run leaves it out or says `default`: the
     * package's own default (`default_fail_on` in blocking.jq), so a run that sent
     * nothing is held to the same rule its build was.
     */
    public const DEFAULT_FAIL_ON = 'medium,security:any,tests:high,maintainability:never';

    /** The heading in the description under which the points for a person are listed, and read back from. */
    public const MANUAL_REVIEW_HEADING = 'For a person to check';

    /**
     * A `fail_on` the server can read. Any category name is accepted, not only the ones
     * listed in {@see self::CATEGORIES}: the run has already checked the spec
     * against its own list, and a newer package with a category this server does not
     * know yet must not lose its whole review to a 422.
     */
    public const FAIL_ON_PATTERN = '/^(never|any|low|medium|high)(,[a-z_]+:(never|any|low|medium|high))*$/';

    /**
     * Severities that count as HIGH for a threshold. OCR can report CRITICAL, which
     * blocking.jq treats as HIGH.
     *
     * @var array<int, string>
     */
    public const HIGH_SEVERITIES = ['HIGH', 'CRITICAL'];

    /**
     * Statuses that mean the finding no longer needs doing, and so get a one-line
     * reply in their thread the run they change.
     *
     * @var array<int, string>
     */
    public const SETTLED = ['fixed', 'acknowledged', 'false_positive'];

    /**
     * Everything that means a finding no longer needs doing. Wider than
     * {@see self::SETTLED}, which is only the ones that earn a reply in the thread.
     *
     * @var array<int, string>
     */
    public const RESOLVED = ['fixed', 'acknowledged', 'false_positive', 'outdated'];

    /**
     * Caps on the model-written text that goes into a comment. Bitbucket rejects a
     * comment beyond its own limit, and a rejected comment is a finding nobody sees;
     * a finding that needs four thousand characters to explain has a bigger problem
     * than the cap.
     */
    public const MAX_DETAILS = 4000;

    public const MAX_TITLE = 300;

    /**
     * A suggestion is never truncated, so this is the length past which it is left
     * out altogether rather than a length it is cut to.
     */
    public const MAX_SUGGESTION = 4000;

    /**
     * Reasons a review was not published that are not failures: there was nothing to
     * post to, or a newer review already covers this commit and overwriting it would
     * move the state backwards. Everything else means the review reached nobody.
     *
     * `no pull request` also answers a pull request that was merged or declined
     * before the review finished: the review is on it as a comment, but there is no
     * open pull request to hold. {@see self::publishToClosed()}
     *
     * @var array<int, string>
     */
    public const NOT_A_FAILURE = [
        'no pull request',
        'a newer review is already published',
    ];

    /**
     * Whether a publish outcome means the review never reached the pull request.
     *
     * @param  array<string, mixed>  $outcome
     */
    public static function failed(array $outcome): bool
    {
        if (($outcome['published'] ?? false) === true) {
            return false;
        }

        return ! in_array($outcome['reason'] ?? '', self::NOT_A_FAILURE, true);
    }

    /**
     * Whether a finding holds the merge. The same rule the run blocks the build
     * on (its `blocking.jq`), so the two can never disagree about what counts.
     *
     * Still open, established, introduced by this change rather than already there,
     * and severe enough for its category's threshold.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function blocks(array $finding, string $failOn): bool
    {
        $confidence = $finding['confidence'] ?? null;

        return in_array(strtolower((string) ($finding['status'] ?? 'new')), self::OPEN, true)
            // Reported, but past the round where a finding below HIGH holds the merge:
            // set by the server alone (ReviewFollowUps), which drops any the reviewer
            // sends, so the step's blocking.jq has no use for it.
            && ($finding['advisory'] ?? false) !== true
            && (! is_numeric($confidence) || (float) $confidence >= self::CONFIDENT)
            && ! self::preExisting($finding)
            && self::reachesThreshold(
                (string) ($finding['severity'] ?? ''),
                $failOn,
                strtolower(trim((string) ($finding['category'] ?? ''))),
            );
    }

    /**
     * Whether a severity reaches the `fail_on` threshold for its category.
     *
     * `fail_on` is one threshold for every category (`medium`), optionally followed
     * by per-category ones: `medium,security:any,maintainability:never`. A category
     * not named, and a finding without one, uses the first.
     */
    public static function reachesThreshold(string $severity, string $failOn, string $category = ''): bool
    {
        $parts = explode(',', self::failOnSpec($failOn));
        $threshold = $parts[0];

        foreach (array_slice($parts, 1) as $part) {
            [$name, $value] = array_pad(explode(':', $part, 2), 2, '');

            if ($category !== '' && $name === $category && $value !== '') {
                $threshold = $value;
            }
        }

        $severity = strtoupper($severity);

        return match ($threshold) {
            'never' => false,
            'any', 'low' => true,
            'high' => in_array($severity, self::HIGH_SEVERITIES, true),
            default => in_array($severity, [...self::HIGH_SEVERITIES, 'MEDIUM'], true),
        };
    }

    /**
     * `fail_on` as blocking.jq reads it (`fail_on_spec`): whitespace removed,
     * lowercased, and the default for an empty value or `default`. A spec that does
     * not parse is the default too.
     */
    public static function failOnSpec(?string $failOn): string
    {
        $spec = strtolower((string) preg_replace('/\s+/', '', (string) $failOn));

        if ($spec === '' || $spec === 'default' || ! preg_match(self::FAIL_ON_PATTERN, $spec)) {
            return self::DEFAULT_FAIL_ON;
        }

        return $spec;
    }

    /**
     * Commits already looked up in this run, by sha.
     *
     * One run settles several findings in the same commit and then names it again in
     * the summary; without this each mention is its own API call for an answer that
     * cannot have changed. `false` records a lookup that failed, so a commit that
     * cannot be read is not re-requested once per finding.
     *
     * @var array<string, array{message: string, author: string}|false>
     */
    private array $commits = [];

    /**
     * Publishes one run's review onto its pull request.
     *
     * Returns what it did, which the run writes to its log, so a publish that
     * went wrong shows up there rather than only in the server's exception
     * tracker.
     *
     * @param  array<string, mixed>  $notes  the review's `changes` and `before_release` lists
     * @return array<string, mixed> `published`, and with it `reason`, `summary`, `comments` and `tasks` as they apply
     */
    public function publish(Repository $repository, ReviewRun $run, string $mode = 'both', bool $createTasks = true, string $failOn = self::DEFAULT_FAIL_ON, array $notes = []): array
    {
        if ($run->pull_request_id === null) {
            return ['published' => false, 'reason' => 'no pull request'];
        }

        $pullRequestId = (string) $run->pull_request_id;

        $comments = $repository->sourceControl()->getPullRequestComments($pullRequestId);

        // Read once, immediately before anything is written: the description is both
        // where the last run's state lives and what this run is about to rewrite.
        $pullRequest = $repository->sourceControl()->getPullRequest($pullRequestId);
        $description = (string) ($pullRequest['description'] ?? '');

        if ($this->wouldOverwriteNewerReview($repository, $run, $description)) {
            return ['published' => false, 'reason' => 'a newer review is already published'];
        }

        $findings = $this->findings($run);
        $previous = $this->previousFindingState($comments);

        // Unlike the other lists, an empty one clears: a point a person had to check
        // goes away when the change no longer does what it was about. Only a report
        // that does not carry the list at all keeps what the last review left.
        $run->manual_review = is_array($notes['manual_review'] ?? null)
            ? $this->listField($notes, 'manual_review')
            : ReviewContext::descriptionList($description, self::MANUAL_REVIEW_HEADING);
        $run->save();

        $counts = ['created' => 0, 'updated' => 0, 'replied' => 0, 'resolved' => 0, 'reopened' => 0];

        // In `report` mode the findings reach the diff as Code Insights annotations,
        // which the run uploads. Posting them as comments as well would say
        // everything twice.
        if ($mode !== 'report') {
            $counts = $this->publishFindings($repository, $pullRequestId, $findings, $previous, $counts, $run->commit);
        }

        // Bitbucket refuses to edit a merged or declined pull request, but still
        // takes comments. A review that finishes after the merge has nowhere else
        // to go, and no merge left to hold.
        $state = strtolower((string) ($pullRequest['state'] ?? 'open'));

        if ($state !== 'open') {
            return $this->publishToClosed($repository, $run, $pullRequestId, $findings, $mode, $description, $notes, $comments, $counts, $state);
        }

        $summary = $this->publishDescription(
            $repository,
            $run,
            $pullRequestId,
            $findings,
            $mode,
            (string) ($pullRequest['title'] ?? ''),
            $description,
            $notes,
        );

        // The description is the review now: comments carry the findings, but this is
        // where the summary, the verdict and the state markers live. If it did not
        // land, the review did not reach the pull request - and the run must not be
        // recorded as the answer, or the retry is skipped as unchanged.
        if (str_starts_with($summary, 'refused')) {
            return ['published' => false, 'reason' => 'the description could not be written ('.$summary.')'];
        }

        return [
            'published' => true,
            'summary' => $summary,
            'comments' => $counts,
            'tasks' => $this->publishTasks($repository, $pullRequestId, $findings, $createTasks, $failOn),
            'verdict' => $this->recordVerdict($repository, $pullRequestId, $run),
        ];
    }

    /**
     * The summary as a comment, for a pull request that was merged or declined
     * before the review finished.
     *
     * The comment carries the review marker, which is what {@see ReviewContext}
     * reads a summary comment by: never as a person talking, and never as a
     * finding's own thread. A later run edits the newest one rather than adding
     * another, and posts a new one when it cannot, such as when a different
     * credential wrote it.
     *
     * No tasks and no verdict: approving or requesting changes on a merged pull
     * request holds nothing. The answer is `no pull request`, which reads as nothing to
     * hold, because there is no open pull request left:
     * any other answer fails the build over findings on a merge that has happened,
     * or claims a request for changes that was never made. It is not a failure in
     * {@see self::NOT_A_FAILURE} either, so the run still counts as answered.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<string, int|string>  $counts
     * @param  array<string, mixed>  $notes
     * @return array<string, mixed>
     */
    protected function publishToClosed(
        Repository $repository,
        ReviewRun $run,
        string $pullRequestId,
        array $findings,
        string $mode,
        string $description,
        array $notes,
        array $comments,
        array $counts,
        string $state,
    ): array {
        $body = "_The pull request was already {$state} when this review finished, so the review is posted here instead of in the description._\n\n"
            .$this->descriptionBlock($repository, $run, $findings, $mode, $notes, $description);

        $summary = $this->updateSummaryComment($repository, $pullRequestId, $comments, $body) ? 'comment updated' : null;

        if ($summary === null) {
            try {
                $repository->sourceControl()->addPullRequestComment($pullRequestId, $body);
                $summary = 'commented';
            } catch (Throwable $exception) {
                report($exception);

                return ['published' => false, 'reason' => "the summary could not be posted on the {$state} pull request"];
            }
        }

        return [
            'published' => false,
            'reason' => 'no pull request',
            'summary' => "{$summary}: the pull request is {$state}",
            'comments' => $counts,
        ];
    }

    /**
     * Rewrites the newest top-level summary comment, and says whether there was one
     * it could rewrite.
     *
     * @param  array<int, array<string, mixed>>  $comments
     */
    protected function updateSummaryComment(Repository $repository, string $pullRequestId, array $comments, string $body): bool
    {
        $summaries = array_filter(
            $comments,
            fn (array $comment): bool => ! isset($comment['parent']) && ! isset($comment['inline'])
                && preg_match(ReviewContext::REVIEW_MARKER, (string) ($comment['content']['raw'] ?? '')) === 1,
        );
        $newest = end($summaries);

        if ($newest === false || ! isset($newest['id'])) {
            return false;
        }

        try {
            $repository->sourceControl()->updatePullRequestComment($pullRequestId, (int) $newest['id'], $body);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * One pull request task per finding that blocks the merge, resolved once it no
     * longer does.
     *
     * "Blocks" is {@see self::blocks()}, the report step's own rule, so the tasks hold
     * exactly what the build and the request for changes hold, and nothing else: a
     * finding below the threshold, unconfirmed or pre-existing gets no task.
     *
     * Tasks carry no marker — Bitbucket gives them no body to hide one in — so the
     * finding id is the first thing in the text and the only way to tell which task
     * belongs to which finding. That is also why the wording is never rewritten:
     * the id has to stay where it is.
     *
     * Never reopens anything, and never creates a second task for a finding that
     * has one, resolved or not. A person who resolves a task has decided something,
     * and a review that un-resolved it every run would be arguing with them.
     *
     * Whether to create them at all is the run's to say, through
     * REVIEW_CREATE_TASKS: the repository that runs the review is the one that
     * knows whether its pull requests want tasks on them.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{created: int, resolved: int, skipped?: string}
     */
    protected function publishTasks(Repository $repository, string $pullRequestId, array $findings, bool $createTasks, string $failOn = self::DEFAULT_FAIL_ON): array
    {
        $counts = ['created' => 0, 'resolved' => 0];

        if (! $createTasks) {
            return $counts + ['skipped' => 'this run asked for no tasks'];
        }

        if (! $repository->sourceControl()->supportsTasks()) {
            return $counts + ['skipped' => 'the host has no tasks; the request for changes holds the merge'];
        }

        try {
            $existing = $this->tasksByFinding(
                $repository->sourceControl()->getPullRequestTasks($pullRequestId),
            );
        } catch (Throwable $exception) {
            // Without the list there is no way to tell a finding that has a task
            // from one that does not, and guessing creates duplicates on a pull
            // request that already has the review on it.
            report($exception);

            return $counts + ['skipped' => 'could not read the existing tasks'];
        }

        foreach ($findings as $finding) {
            $id = (string) ($finding['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // Done with, or no longer holding the merge: below the confidence
            // threshold, no longer reported, or not this change's doing. A task is a
            // merge check, so one left open by such a finding blocks exactly what
            // that finding may no longer block - the review would be enforcing
            // something it has stopped claiming. Resolving is the only direction this
            // goes; a task is never reopened, here or anywhere.
            $blocks = self::blocks($finding, $failOn);
            $task = $existing[$id] ?? null;

            try {
                if ($task === null) {
                    // Gated the same way the verdict is: a task is a merge check of its
                    // own, so a finding that cannot block creating one would block
                    // through the back door what it cannot block directly.
                    if ($blocks) {
                        $repository->sourceControl()->createPullRequestTask($pullRequestId,
                            $this->taskContent($finding),
                        );
                        $counts['created']++;
                    }

                    continue;
                }

                if (! $blocks && strtoupper((string) ($task['state'] ?? '')) !== 'RESOLVED') {
                    $repository->sourceControl()->resolvePullRequestTask($pullRequestId,
                        (int) $task['id'],
                        (string) ($task['content']['raw'] ?? $this->taskContent($finding)),
                    );
                    $counts['resolved']++;
                }
            } catch (Throwable $exception) {
                // One task is not worth losing the rest of the review over.
                report($exception);
            }
        }

        return $counts;
    }

    /**
     * The existing tasks, by the finding id each one opens with.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, array<string, mixed>>
     */
    protected function tasksByFinding(array $tasks): array
    {
        $byFinding = [];

        foreach ($tasks as $task) {
            if (! preg_match('/^\s*(?<id>[A-Za-z0-9_.-]+):/', (string) ($task['content']['raw'] ?? ''), $matches)) {
                continue;
            }

            // First wins, so a duplicate left by an older run is not the one we
            // resolve while the other stays open.
            $byFinding[$matches['id']] ??= $task;
        }

        return $byFinding;
    }

    /**
     * A task's text. The finding id comes first because it is what identifies it.
     *
     * @param  array<string, mixed>  $finding
     */
    public function taskContent(array $finding): string
    {
        return ($finding['id'] ?? '?').': '.$this->title($finding).' ('.$this->location($finding).')';
    }

    /**
     * Posts, edits and replies to the one comment per finding.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array{created: int, updated: int, replied: int, resolved: int, reopened: int}  $counts
     * @return array{created: int, updated: int, replied: int, resolved: int, reopened: int, not_resolved?: int, resolve_error?: string}
     */
    protected function publishFindings(
        Repository $repository,
        string $pullRequestId,
        array $findings,
        array $previous,
        array $counts,
        ?string $commit = null,
    ): array {
        foreach ($findings as $finding) {
            $id = (string) ($finding['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $existing = $previous[$id] ?? null;
            $commentId = $existing['comment_id'] ?? null;
            // The comment as it stands is an input: a settled finding keeps what it
            // already said rather than being rewritten from this run.
            $body = $this->findingBody(
                $finding,
                $existing['raw'] ?? null,
                // Keyed on the comment, not on whether the finding is known. A run in
                // report mode leaves a marker for every finding in the summary and
                // no comment of its own, so the finding is familiar while its comment
                // does not exist yet - and that first comment is posted by
                // postFinding, which tries inline and rebuilds without the suggestion
                // only if Bitbucket refuses. Suppress it when editing a comment that
                // is not anchored to a line, and only then.
                allowSuggestion: $commentId === null || (bool) ($existing['inline'] ?? false),
            );

            try {
                if ($commentId === null) {
                    $this->postFinding($repository, $pullRequestId, $finding, $body);
                    $counts['created']++;

                    continue;
                }

                // An edit that changes nothing still shows as activity on the pull
                // request, so only write when the body actually moved. Compared
                // against the comment as it stands rather than against its marker:
                // the marker carries no title or details, so a marker-only
                // comparison reports every finding as changed on every run.
                if (trim((string) ($existing['raw'] ?? '')) !== trim($body)) {
                    $repository->sourceControl()->updatePullRequestComment($pullRequestId,
                        (int) $commentId,
                        $body,
                    );
                    $counts['updated']++;
                }

                // Only on the run the status settles, never again: the previous
                // marker is what says whether this is the transition or a repeat.
                $status = strtolower((string) ($finding['status'] ?? ''));
                $was = strtolower((string) ($existing['status'] ?? ''));

                // The thread follows the finding: collapsed once it is done with,
                // open again if it comes back. Neither is tied to the transition the
                // way the reply is - a resolve that failed on the run the finding
                // settled should still happen on the next one.
                //
                // Re-opening does not argue with anyone. Someone resolving a thread
                // is read as acknowledgement by the next review, and an acknowledged
                // finding stays resolved; so a thread only re-opens when the review
                // itself says the problem is live again, which is a regression worth
                // surfacing rather than leaving collapsed where nobody will see it.
                $settledNow = in_array($status, self::RESOLVED, true);
                $wasResolved = (bool) ($existing['resolved'] ?? false);

                // On its own: a thread that will not resolve must not cost the reply
                // that says why, which is said only on the run the status settles.
                if ($settledNow !== $wasResolved) {
                    try {
                        $repository->sourceControl()->resolvePullRequestComment($pullRequestId,
                            (int) $commentId,
                            $settledNow,
                        );
                        $counts[$settledNow ? 'resolved' : 'reopened']++;
                    } catch (Throwable $exception) {
                        $counts = self::unresolved($counts, $exception);
                    }
                }

                // The review's comment on someone's decision about a finding: a
                // false positive it does not agree with, or a fix that is not one.
                // It never changes the status, and it is never said twice in a row:
                // a thread that already ends with a reply of the review's gets
                // nothing more until a person writes again.
                $reply = trim((string) ($finding['reply'] ?? ''));
                $reply = $reply !== '' && in_array($status, ['open', 'false_positive'], true) && ! ($existing['review_spoke_last'] ?? false)
                    ? Str::limit($reply, self::MAX_DETAILS)
                    : '';

                if (in_array($status, self::SETTLED, true) && $status !== $was) {
                    $repository->sourceControl()->addPullRequestComment($pullRequestId,
                        match ($status) {
                            'fixed' => 'Fixed in '.$this->describeCommit($repository, $commit).'.',
                            // A person's decision, which the review does not
                            // override; its comment on it replaces the plain line.
                            'false_positive' => $reply !== '' ? $reply : 'Marked as a false positive.',
                            default => 'Acknowledged; leaving this as it is.',
                        }
                        ."\n\n".self::REPLY_MARKER,
                        parentId: (int) $commentId,
                    );
                    $counts['replied']++;

                    $reply = '';
                }

                // Said once, on the run it stops being reported. The thread stays open:
                // whether it was fixed somewhere else is for a person to say, and
                // resolving it is how they say so.
                if ($status === 'unreported' && $was !== 'unreported') {
                    $repository->sourceControl()->addPullRequestComment($pullRequestId,
                        'The review no longer reports this, but the code it is about has not changed, so it blocks nothing now. '
                            .'If it was fixed somewhere else, resolve this thread.'
                            ."\n\n".self::REPLY_MARKER,
                        parentId: (int) $commentId,
                    );
                    $counts['replied']++;
                }

                if ($reply !== '') {
                    $repository->sourceControl()->addPullRequestComment($pullRequestId,
                        $reply."\n\n".self::REPLY_MARKER,
                        parentId: (int) $commentId,
                    );
                    $counts['replied']++;
                }
            } catch (Throwable $exception) {
                // One finding that will not post must not cost the other findings or
                // the summary. The summary lists every finding either way, so nothing
                // is lost from the review itself.
                report($exception);
            }
        }

        // A finding this run no longer reports because it was settled before, whose
        // thread was left open: the run that settled it could not resolve it.
        // Resolved now, as that run would have. Only where nobody but the review
        // wrote under it since: a thread a person answered is theirs to close.
        $reported = array_flip(array_map(fn (array $finding): string => (string) ($finding['id'] ?? ''), $findings));

        foreach ($previous as $id => $existing) {
            $commentId = $existing['comment_id'] ?? null;

            if (isset($reported[$id]) || $commentId === null || ($existing['resolved'] ?? false)
                || (($existing['replied'] ?? true) && ! ($existing['review_spoke_last'] ?? false))
                || ! in_array(strtolower((string) ($existing['status'] ?? '')), self::RESOLVED, true)) {
                continue;
            }

            try {
                $repository->sourceControl()->resolvePullRequestComment($pullRequestId, (int) $commentId);
                $counts['resolved']++;
            } catch (Throwable $exception) {
                $counts = self::unresolved($counts, $exception);
            }
        }

        return $counts;
    }

    /**
     * Counts a thread that would not resolve, with the host's first word on why,
     * for the run's log: the reply under it is posted either way, so on the pull
     * request it reads as fixed and still open.
     *
     * @param  array{created: int, updated: int, replied: int, resolved: int, reopened: int, not_resolved?: int, resolve_error?: string}  $counts
     * @return array{created: int, updated: int, replied: int, resolved: int, reopened: int, not_resolved?: int, resolve_error?: string}
     */
    protected static function unresolved(array $counts, Throwable $exception): array
    {
        // A GitHub App or token without Contents write is refused this, and that is a choice
        // made on purpose: the finding's first line and the reply say it is done.
        if (! self::refusedByPermission($exception)) {
            report($exception);
        }

        $counts['not_resolved'] = ($counts['not_resolved'] ?? 0) + 1;
        $counts['resolve_error'] ??= mb_substr($exception->getMessage(), 0, 300);

        return $counts;
    }

    /**
     * Whether the host refused for want of a permission, rather than failed.
     */
    public static function refusedByPermission(Throwable $exception): bool
    {
        // "by integration" for an app, "by personal access token" for a token.
        return str_contains($exception->getMessage(), 'Resource not accessible by');
    }

    /**
     * Posts one finding, inline where Bitbucket will take it.
     *
     * A line that no longer exists in the diff is rejected, and a finding nobody can
     * see is the one outcome worth avoiding — so it goes up as a general comment
     * naming the place instead.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function postFinding(Repository $repository, string $pullRequestId, array $finding, string $body): void
    {
        $path = $finding['path'] ?? null;
        $line = isset($finding['line']) && is_numeric($finding['line']) ? (int) $finding['line'] : null;

        try {
            $repository->sourceControl()->addPullRequestComment($pullRequestId,
                $body,
                is_string($path) && $path !== '' ? $path : null,
                $line,
            );
        } catch (Throwable) {
            // Not inline after all, so the suggestion has nothing to replace and is
            // rebuilt out rather than left as an Apply button that does nothing.
            $repository->sourceControl()->addPullRequestComment($pullRequestId,
                $this->location($finding)."\n\n".$this->findingBody($finding, null, allowSuggestion: false),
            );
        }
    }

    /**
     * Puts the review into the pull request's own description, between the markers.
     *
     * The description rather than a comment because that is where someone arriving
     * at the pull request looks first, and because it stays at the top however long
     * the conversation grows. Whatever a person wrote is kept: only the fenced block
     * is replaced, and a description with no block yet gets one appended.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<string, mixed>  $notes
     */
    protected function publishDescription(
        Repository $repository,
        ReviewRun $run,
        string $pullRequestId,
        array $findings,
        string $mode,
        string $title,
        string $current,
        array $notes = [],
    ): string {
        $updated = $this->spliceBlock($current, $this->descriptionBlock($repository, $run, $findings, $mode, $notes, $current));

        if (trim($updated) === trim($current)) {
            return 'unchanged';
        }

        $status = $repository->sourceControl()->updatePullRequestDescription($pullRequestId,
            $title,
            $updated,
        );

        if ($status < 200 || $status >= 300) {
            return 'refused: HTTP '.$status;
        }

        return str_contains($current, self::DESCRIPTION_START) ? 'updated' : 'added';
    }

    /**
     * The description with our block swapped in, and everything else left alone.
     *
     * A description that never had a block gets one after what is already there,
     * separated by a rule so the two do not read as one piece of writing.
     */
    public function spliceBlock(string $description, string $block): string
    {
        if (preg_match(self::DESCRIPTION_BLOCK, $description)) {
            // A callback, not a replacement string. preg_replace reads `$0`, `$1`
            // and `${…}` in its replacement as back-references, and this block is
            // full of PHP written by a review of PHP — a prompt naming `$user`, a
            // suggestion assigning `$plan`. `$0` is the whole match, so the previous
            // review gets spliced into the middle of the new one and the block eats
            // itself. A callback returns the text literally and reads nothing.
            return (string) preg_replace_callback(
                self::DESCRIPTION_BLOCK,
                static fn (): string => "\n\n".$block."\n",
                $description,
                1,
            );
        }

        $description = rtrim($description);

        // A first run on a pull request nobody described still leaves room to
        // describe it: the placeholder sits outside the fence, so it survives every
        // later run and whatever replaces it survives too.
        if ($description === '') {
            $description = self::DESCRIPTION_PLACEHOLDER;
        }

        return $description."\n\n---\n\n".$block;
    }

    /**
     * Approves the pull request, or asks for changes, and takes back whichever of
     * the two no longer applies. A review that passes but left something for a
     * person to check does neither, and takes both back: the pull request waits for
     * a person's approval.
     *
     * Both are said every run rather than only on a change: an approval that was
     * never withdrawn when findings appeared would be worse than no approval at all.
     *
     * Only the commit that was reviewed is approved: when the pull request has moved
     * on to a newer one while the review ran, it approves nothing and takes back an
     * approval that stands, as that would approve code nobody reviewed. A request for
     * changes stays until the review of the newer commit says otherwise.
     *
     * Never throws. Bitbucket may refuse a participant action from an access token,
     * which costs a nicety and nothing else - the findings are on the pull request
     * either way.
     */
    protected function recordVerdict(Repository $repository, string $pullRequestId, ReviewRun $run): string
    {
        $failing = strtoupper((string) ($run->result ?? '')) === 'FAILED';

        try {
            // Read again just before: a push may have landed while the review was published.
            $head = (string) ($repository->sourceControl()->getPullRequest($pullRequestId)['source']['commit']['hash'] ?? '');
            $moved = $head !== '' && ! ReviewContext::isSameCommit($head, $run->commit);
            $approving = ! $failing && ! $run->needsManualReview() && ! $moved;

            $changes = $repository->sourceControl()->requestChanges($pullRequestId, $failing, $run->commit);
            $approval = $repository->sourceControl()->approve($pullRequestId, $approving, $run->commit);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed: '.$exception->getMessage();
        }

        // 404 means "there was nothing there", which is a fine answer to *removing*
        // a flag and a failure when setting one: a request for changes that 404s did
        // not land, and reporting it as though it had would leave the merge open
        // with nothing holding it. Each call is a POST in one direction and a DELETE
        // in the other, so which one is forgiving depends on the verdict.
        $ok = static fn (int $status, bool $withdrawing): bool => ($status >= 200 && $status < 300)
            || ($withdrawing && $status === 404);

        if (! $ok($changes, ! $failing) || ! $ok($approval, ! $approving)) {
            return sprintf('refused (changes HTTP %d, approval HTTP %d)', $changes, $approval);
        }

        return match (true) {
            $failing => 'changes requested',
            $approving => 'approved',
            $moved => 'not approved: the pull request moved on to '.substr($head, 0, 12).' while '.substr($run->commit, 0, 12).' was reviewed',
            default => 'manual review',
        };
    }

    /**
     * What the review came to, as the reader of a pull request would say it: a
     * review that asks for changes has not "failed", and neither has the author.
     */
    public static function verdictInWords(ReviewRun $run): string
    {
        return match (true) {
            $run->needsManualReview() => 'Manual review required',
            strtoupper((string) ($run->result ?? '')) === 'FAILED' => 'Changes requested',
            default => 'Approved',
        };
    }

    /**
     * A reviewer's text with a blank line before each list that follows a line of
     * prose. Without one the host reads the items as more of the same paragraph
     * and shows them as one line with dashes in it.
     */
    public static function spaced(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $spaced = [];
        $fenced = false;

        foreach ($lines as $index => $line) {
            $fenced = preg_match('/^\s*(```|~~~)/', $line) === 1 ? ! $fenced : $fenced;
            $previous = $index === 0 ? '' : $lines[$index - 1];
            $isItem = preg_match('/^\s{0,3}([-*+]|\d+[.)])\s+\S/', $line) === 1;
            $follows = trim($previous) !== '' && preg_match('/^\s*([-*+]|\d+[.)])\s+\S/', $previous) !== 1 && preg_match('/^\s{2,}\S/', $previous) !== 1;

            if (! $fenced && $isItem && $follows) {
                $spaced[] = '';
            }

            $spaced[] = $line;
        }

        return implode("\n", $spaced);
    }

    /**
     * The summary comment, whole.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<string, mixed>  $notes
     */
    public function descriptionBlock(Repository $repository, ReviewRun $run, array $findings, string $mode = 'both', array $notes = [], string $current = ''): string
    {
        $open = array_values(array_filter(
            $findings,
            fn (array $finding): bool => in_array(strtolower((string) ($finding['status'] ?? 'new')), self::OPEN, true),
        ));

        // Still on the pull request for a person to settle, so listed with the open
        // ones, but not work the review asks for: it is left out of "fix these".
        $unreported = array_values(array_filter(
            $findings,
            fn (array $finding): bool => strtolower((string) ($finding['status'] ?? '')) === 'unreported',
        ));

        $lines = [
            self::DESCRIPTION_START,
            '',
            '## AI Review',
            '',
            '**Result:** '.self::verdictInWords($run),
            '',
            'Reviewed'.($run->model ? ' by '.$run->model : '').' up to '.$this->describeCommit($repository, $run->commit),
        ];

        if (($run->summary ?? '') !== '') {
            $lines[] = '';
            $lines[] = '### Summary';
            $lines[] = '';
            $lines[] = self::spaced(trim((string) $run->summary));
        }

        if (($run->manual_review ?? []) !== []) {
            $lines[] = '';
            $lines[] = '### '.self::MANUAL_REVIEW_HEADING;
            $lines[] = '';

            foreach ($run->manual_review as $item) {
                $lines[] = '- '.$item;
            }

            // Said here, because a missing approval is otherwise only an absence. After
            // the list, which is read back from this heading up to the first prose.
            $lines[] = '';
            $lines[] = $run->needsManualReview()
                ? 'The review found nothing that holds the merge, and has not approved it: these points are for a person to settle.'
                : 'Besides the findings, these points are for a person to settle.';
        }

        $lines[] = '';
        $lines[] = $this->ratingLine($run);

        foreach ([
            'changes' => 'Biggest changes',
            'before_release' => 'Before release',
        ] as $field => $heading) {
            $items = $this->listField($notes, $field);

            // These describe the pull request, not this run. A review that reviewed
            // only the newest commits may say nothing about either — and replacing
            // what is there with nothing would lose the record of everything the
            // earlier runs described. Silence keeps; it never clears.
            if ($items === []) {
                $items = ReviewContext::descriptionList($current, $heading);
            }

            if ($items === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '### '.$heading;

            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }
        }

        $lines[] = '';
        // Two lists, because they are read for different reasons: the first is work
        // to do, the second is the record of what was. Mixed together, a long list of
        // settled findings buries the two that are not.
        if ($findings === []) {
            $lines[] = '### Findings';
            $lines[] = '';
            $lines[] = 'No findings.';
        } else {
            $settled = array_values(array_filter(
                $findings,
                fn (array $finding): bool => in_array(strtolower((string) ($finding['status'] ?? 'new')), self::RESOLVED, true),
            ));

            $lines[] = '### Open findings';
            $lines[] = '';
            $lines[] = $open === [] && $unreported === []
                ? 'None. Everything the review raised has been dealt with.'
                : $this->findingsList([...$open, ...$unreported]);

            if ($settled !== []) {
                $lines[] = '';
                $lines[] = '### Resolved';
                $lines[] = '';
                $lines[] = $this->findingsList($settled);
            }
        }

        // Rebuilt from what is open *now*, every run. An empty "fix these" reads as
        // though the review did not finish, so it is left out entirely instead.
        if ($open !== []) {
            $lines[] = '';
            $lines[] = '### Fix these';
            $lines[] = '';
            $lines[] = self::fenced($this->fixThesePrompt($repository, $run, $open), 'text');
        }

        $lines[] = '';
        // `at` so the next run knows when this was written. The description carries
        // no timestamp of its own the way a comment does, and without one there is
        // no telling which of the conversation's comments arrived after the review.
        $lines[] = '[//]: # (ai-review reviewed='.$run->commit.' mode='.$mode
            .' at='.($run->finished_at ?? now())->toIso8601String().')';

        // In comment mode each finding's state lives on its own comment, so repeating
        // it here would give the next run two sources for one finding. In every other
        // mode this is the only place it is written — including for fixed and
        // acknowledged findings, or the next run forgets they were ever settled.
        if ($mode !== 'comment') {
            foreach ($this->sorted($findings) as $finding) {
                $lines[] = $this->marker($finding);
            }
        }

        $lines[] = self::DESCRIPTION_END;

        return implode("\n", $lines);
    }

    /**
     * A link to rate this run. Opening it saves nothing: link previews fetch every
     * URL in a description, and a fetch is not a vote. The token in it is the run's
     * own, so whoever can read the pull request can rate its review.
     */
    protected function ratingLine(ReviewRun $run): string
    {
        return '[Rate this review]('.route('reviews.rate', ['token' => $run->ratingToken()]).')';
    }

    /**
     * One of the review's list fields, as clean lines.
     *
     * The review writes these and they arrive with the report; anything that is not
     * a list of strings is dropped rather than rendered as whatever it happens to be.
     * A line over 500 characters is shortened, not refused.
     *
     * @param  array<string, mixed>  $notes
     * @return array<int, string>
     */
    protected function listField(array $notes, string $field): array
    {
        $value = $notes[$field] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? trim(Str::limit($item, 500)) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * One list item per finding: the label, status and title on the first line,
     * the location on a nested line below.
     *
     * Not a table: a table cell cannot hold a line break when the description is
     * written through the API. A raw newline ends the row and Bitbucket renders no
     * HTML, so title and path ended up on one wrapped line. A nested item is the
     * line break every renderer agrees on. The status sits with the short label
     * rather than after the path, which is usually the longest thing here.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function findingsList(array $findings): string
    {
        $items = [];

        foreach ($this->sorted($findings) as $finding) {
            $status = strtolower((string) ($finding['status'] ?? 'new'));

            $items[] = '- **'.$this->heading($finding).' · '.(self::STATUS_LABELS[$status] ?? ucfirst($status)).'** '
                .$this->title($finding);
            $items[] = '    - `'.$this->location($finding).'`';
        }

        return implode("\n", $items);
    }

    /**
     * One finding's own comment.
     *
     * @param  array<string, mixed>  $finding
     */
    public function findingBody(array $finding, ?string $existingRaw = null, bool $allowSuggestion = true): string
    {
        // Once a finding is settled its comment is history, and history is not
        // rewritten. The reply underneath says what became of it, so restating it
        // in the comment would say the same thing twice, in whatever words the
        // latest run happened to use. Only its first line takes the status, as the
        // description lists it, so the comment says it is done where a thread
        // cannot be resolved; and the marker moves, which is invisible.
        if (in_array($status = strtolower((string) ($finding['status'] ?? 'new')), self::RESOLVED, true) && $existingRaw !== null) {
            return $this->withMarker($this->withStatus($existingRaw, $finding, $status), $finding);
        }

        $details = $this->details($finding);
        $evidence = $this->evidence($finding);
        $prompt = $this->prompt($finding);
        $suggestion = $allowSuggestion ? $this->suggestion($finding) : '';

        // What kind of finding it is on its own line and the title below it, so the
        // title reads as a heading rather than the tail of a label.
        return $this->heading($finding)."\n\n**".$this->title($finding).'**'
            .($details === '' ? '' : "\n\n".$details)
            // How the review established it, set apart from the claim itself.
            // Bitbucket renders no HTML, so there is nothing to collapse it into; a
            // bold line keeps it short where a heading would outweigh the title.
            .($evidence === '' ? '' : "\n\n**Why this was flagged**\n\n".$evidence)
            // Bitbucket turns this into an Apply button that commits the replacement
            // to the branch, so it is only ever what the review wrote and only on a
            // comment that is actually anchored to a line.
            .($suggestion === '' ? '' : "\n\n".self::fenced($suggestion, 'suggestion'))
            // A `text` block so it can be copied in one go into a coding agent. It is
            // per finding on purpose: the summary gathers them all into "Fix these",
            // but the one finding someone is looking at is usually the one they want
            // to hand over, and scrolling up to the summary to find its line again is
            // the friction this removes.
            .($prompt === '' ? '' : "\n\n**Prompt for a coding agent:**\n\n".self::fenced($prompt, 'text'))
            ."\n\n".$this->marker($finding);
    }

    /**
     * A settled finding's comment with its status on the first line, `F1 · HIGH ·
     * ✅ Fixed`, where that line is the finding's heading: one a person wrote over,
     * or a comment from before headings, is left as it is.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function withStatus(string $raw, array $finding, string $status): string
    {
        $id = trim((string) ($finding['id'] ?? ''));
        $first = strtok($raw, "\n");

        if ($id === '' || $first === false || ! str_starts_with($first, $id.' · ')) {
            return $raw;
        }

        return $this->heading($finding).' · '.(self::STATUS_LABELS[$status] ?? ucfirst($status)).substr($raw, strlen($first));
    }

    /**
     * The block someone pastes into a coding agent.
     *
     * Each line carries what to change, which finding it is, and why the review
     * thinks so. Without the reason an agent can only take the instruction on trust,
     * which is the opposite of what a finding is: a claim to check. How to answer a
     * finding on the pull request is left to the agent's own skills; repeating it
     * here on every run only made the block longer.
     *
     * @param  array<int, array<string, mixed>>  $open
     */
    protected function fixThesePrompt(Repository $repository, ReviewRun $run, array $open): string
    {
        $where = $run->pull_request_id
            ? $repository->workspace.'/'.$repository->slug.' pull request '.$run->pull_request_id.' (branch '.$run->branch.')'
            : 'branch '.$run->branch;

        $lines = [
            'Check the review findings on '.$where.' against the code, and review the suggested fixes before applying them.',
            '',
        ];

        foreach (array_values($this->sorted($open)) as $index => $finding) {
            // The finding's own prompt where it wrote one, so the block someone
            // pastes says exactly what the comment on that line says. Only a
            // finding without one falls back to a line built from its title.
            $prompt = $this->prompt($finding);
            $reason = trim($this->firstSentence($finding), ' ');

            $lines[] = ($index + 1).'. '.$this->reference($finding).' — '
                .($prompt !== '' ? $prompt : $this->title($finding).'.'.$this->firstSentence($finding));

            // Only alongside a prompt: the fallback line above already ends in this
            // very sentence, and repeating it reads like two different reasons.
            if ($prompt !== '' && $reason !== '') {
                $lines[] = '   Why: '.$reason;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * How a line names its finding: where it is, and which finding it is. The id is
     * what `pull-request-feedback` and the finding's own comment carry, so it is
     * what someone points at when they want to talk about one of them.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function reference(array $finding): string
    {
        $parts = [$this->location($finding)];

        if (filled($finding['id'] ?? null)) {
            $parts[] = 'finding '.$finding['id'];
        }

        return implode(' · ', $parts);
    }

    /**
     * The instruction an agent can act on, as the review wrote it.
     *
     * Never invented here. A finding with no `prompt` gets no block rather than a
     * sentence assembled out of its title, which would read like an instruction
     * without being one.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function prompt(array $finding): string
    {
        return Str::limit(trim((string) ($finding['prompt'] ?? '')), self::MAX_DETAILS);
    }

    /**
     * The replacement for the line this comment is anchored to, if the review wrote
     * one it is sure of.
     *
     * Only ever rendered on a comment with a file and a line: a suggestion block
     * replaces the lines its comment covers, so on a comment that covers none it is
     * a code block with an Apply button that does nothing. Trailing whitespace is
     * kept - it is code, and its indentation is load-bearing - but a trailing newline
     * is not, because the fence adds one.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function suggestion(array $finding): string
    {
        $suggestion = rtrim((string) ($finding['suggestion'] ?? ''), "\r\n");

        if (trim($suggestion) === '' || trim((string) ($finding['path'] ?? '')) === '') {
            return '';
        }

        if (! isset($finding['line']) || ! is_numeric($finding['line'])) {
            return '';
        }

        // Never shortened. Every other field here is prose, where an ellipsis reads
        // as "there was more"; this one is committed verbatim by the Apply button,
        // where it reads as code and does not compile. A replacement too long to
        // carry is dropped instead - `details` and `prompt` still say what to do,
        // and no Apply button is better than one that breaks the branch.
        return mb_strlen($suggestion) > self::MAX_SUGGESTION ? '' : $suggestion;
    }

    /**
     * A comment with its finding marker brought up to date and nothing else touched.
     *
     * The marker is replaced in place rather than the comment being rebuilt, so
     * whatever the body says - a quoted snippet, someone's formatting, an emoji -
     * comes back byte for byte. The previous version rebuilt it, and splitting the
     * body into lines is what cut a ✅ in half and made the send throw.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function withMarker(string $raw, array $finding): string
    {
        $replaced = preg_replace(
            '/^\[\/\/\]:\s*#\s*\(ai-finding\b[^\n]*/mu',
            // A `$` in the replacement would be read as a back-reference.
            str_replace('$', '\\$', $this->marker($finding)),
            $raw,
            1,
            $count,
        );

        // A comment whose marker someone removed still belongs to this finding - it
        // was matched by one a moment ago. Put it back rather than lose the state.
        return $count > 0 && $replaced !== null
            ? $replaced
            : rtrim($raw)."\n\n".$this->marker($finding);
    }

    /**
     * A finding's marker line.
     *
     * Every field, every time: the next run rebuilds the finding from this alone, so
     * one left out is a finding it cannot carry forward. A `)` in the path would end
     * the marker early, so such a path is left out and the body names the file.
     *
     * @param  array<string, mixed>  $finding
     */
    public function marker(array $finding): string
    {
        $parts = [
            'id='.($finding['id'] ?? ''),
            'status='.strtolower((string) ($finding['status'] ?? 'new')),
            'severity='.$this->severity($finding),
        ];

        $path = (string) ($finding['path'] ?? '');

        if ($path !== '' && ! str_contains($path, ')')) {
            $parts[] = 'path='.$path;
        }

        if (isset($finding['line']) && is_numeric($finding['line'])) {
            $parts[] = 'line='.(int) $finding['line'];
        }

        return '[//]: # (ai-finding '.implode(' ', $parts).')';
    }

    /**
     * Whether the summary already on the pull request came from a review of a commit
     * that was reviewed *later* than this one.
     *
     * Two runs can finish in either order. The rule is that a review never moves the
     * `reviewed=` marker backwards, because the next run would then re-review ground
     * the newer one already covered. Git would answer this with `merge-base
     * --is-ancestor`, which the server cannot run — but it does not need to: it knows
     * when each run started, and the later run's answer is the current one.
     */
    protected function wouldOverwriteNewerReview(Repository $repository, ReviewRun $run, string $description): bool
    {
        if ($description === '') {
            return false;
        }

        preg_match_all(ReviewContext::REVIEW_MARKER, $description, $matches, PREG_SET_ORDER);

        $publishedCommit = $matches === [] ? null : end($matches)['commit'];

        if ($publishedCommit === null || ReviewContext::isSameCommit($run->commit, $publishedCommit)) {
            return false;
        }

        // An unknown commit is not a reason to withhold a review: it may predate the
        // server, or have come from a repository that was not recording runs yet.
        $published = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $run->pull_request_id)
            ->where('commit', 'like', $publishedCommit.'%')
            ->whereNotNull('started_at')
            ->latest('started_at')
            ->first();

        return $published !== null && $published->started_at->greaterThan($run->started_at);
    }

    /**
     * The findings the run recorded, as a list.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function findings(ReviewRun $run): array
    {
        $findings = $run->findings;

        if (! is_array($findings)) {
            return [];
        }

        return array_values(array_filter($findings, 'is_array'));
    }

    /**
     * What the pull request already says about each finding, by id: the comment that
     * carries it and the status that comment records.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<string, array<string, mixed>>
     */
    protected function previousFindingState(array $comments): array
    {
        $state = [];

        foreach ($comments as $comment) {
            if ($comment['deleted'] ?? false) {
                continue;
            }

            preg_match_all(
                ReviewContext::FINDING_MARKER,
                (string) ($comment['content']['raw'] ?? ''),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $finding = [];

                foreach (explode(' ', trim($match['attributes'])) as $pair) {
                    [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

                    if ($value !== null) {
                        $finding[$key] = $value;
                    }
                }

                if (! isset($finding['id'])) {
                    continue;
                }

                // The summary carries a marker for every finding, so it would claim
                // each one's comment id. A finding's own comment is the one whose
                // body is only that finding, which is to say: not the summary.
                $isSummary = (bool) preg_match(ReviewContext::REVIEW_MARKER, (string) ($comment['content']['raw'] ?? ''));

                if ($isSummary && isset($state[$finding['id']])) {
                    continue;
                }

                $finding['comment_id'] = $isSummary ? ($state[$finding['id']]['comment_id'] ?? null) : ($comment['id'] ?? null);
                // Kept so the body can be compared with what a fresh render would
                // produce, which is the only way to tell a finding that changed from
                // one that did not.
                $finding['raw'] = $isSummary ? ($state[$finding['id']]['raw'] ?? null) : ($comment['content']['raw'] ?? null);
                // Bitbucket sets `resolution` when a thread is resolved; whoever did
                // it, it does not need resolving again.
                $finding['resolved'] = $isSummary
                    ? ($state[$finding['id']]['resolved'] ?? false)
                    : (($comment['resolution'] ?? null) !== null);
                // Whether the comment is anchored to a line at all. One that is not -
                // because Bitbucket refused the placement when it was posted - can
                // no more carry a suggestion on an edit than it could when it was
                // written.
                $finding['inline'] = $isSummary
                    ? ($state[$finding['id']]['inline'] ?? false)
                    : (($comment['inline'] ?? null) !== null);

                $state[$finding['id']] = $finding;
            }
        }

        $lastReplies = $this->lastReplies($comments);

        foreach ($state as $id => $finding) {
            $last = $lastReplies[(int) ($finding['comment_id'] ?? 0)] ?? null;
            $state[$id]['review_spoke_last'] = $last !== null && str_contains($last, self::REPLY_MARKER);
            $state[$id]['replied'] = $last !== null;
        }

        return $state;
    }

    /**
     * The newest reply in each thread, by the id of the comment that opened it.
     *
     * Bitbucket nests a reply to a reply under that reply, so each comment is walked
     * up to the top of its thread. Ids only grow, so the highest is the newest.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, string>
     */
    protected function lastReplies(array $comments): array
    {
        $parents = [];

        foreach ($comments as $comment) {
            if (isset($comment['id'], $comment['parent']['id'])) {
                $parents[(int) $comment['id']] = (int) $comment['parent']['id'];
            }
        }

        $last = [];

        foreach ($comments as $comment) {
            if (($comment['deleted'] ?? false) || ! isset($comment['id'], $parents[(int) $comment['id']])) {
                continue;
            }

            $root = (int) $comment['id'];

            while (isset($parents[$root])) {
                $root = $parents[$root];
            }

            if (! isset($last[$root]) || (int) $comment['id'] > $last[$root]['id']) {
                $last[$root] = ['id' => (int) $comment['id'], 'raw' => (string) ($comment['content']['raw'] ?? '')];
            }
        }

        return array_map(static fn (array $reply): string => $reply['raw'], $last);
    }

    /**
     * Worst first, and within a severity the ones still needing work first.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    protected function sorted(array $findings): array
    {
        $rank = static function (array $finding): array {
            $severity = array_search(strtoupper((string) ($finding['severity'] ?? '')), self::SEVERITIES, true);
            $status = strtolower((string) ($finding['status'] ?? 'new'));

            return [
                $severity === false ? count(self::SEVERITIES) : $severity,
                match (true) {
                    in_array($status, self::OPEN, true) => 0,
                    $status === 'unreported' => 1,
                    default => 2,
                },
            ];
        };

        usort($findings, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    /**
     * A finding's kind, or an empty string for the default.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function category(array $finding): string
    {
        $category = strtolower(trim((string) ($finding['category'] ?? '')));

        return $category === 'correctness' || ! in_array($category, self::CATEGORIES, true) ? '' : $category;
    }

    /**
     * The severity as it is shown, with the category beside it when there is one.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function label(array $finding): string
    {
        $category = $this->category($finding);

        return $this->severity($finding)
            .($category === '' ? '' : ' · '.$category)
            // Said on the finding itself, not left in the small print. A reader
            // seeing "HIGH" reasonably assumes the review established it; where it
            // did not, the label is the honest place to say so, and it is why an
            // unproven finding can be reported at its real severity without costing
            // the rest of the review its credibility.
            .($this->confident($finding) ? '' : ' · unconfirmed')
            // The other reason a finding at its severity blocks nothing: the code was
            // like this before the change, which is worth fixing and not this pull
            // request's to hold.
            .(self::preExisting($finding) ? ' · Pre-existing' : '');
    }

    /**
     * Whether the finding was already there before this pull request. Only an
     * explicit `false` says so; a finding without the field was introduced here.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function preExisting(array $finding): bool
    {
        return ($finding['introduced'] ?? true) === false;
    }

    /**
     * The id and label together, `F1 · HIGH · security`, shown above or beside the
     * finding's title.
     *
     * The id is how a finding is named everywhere else — "Fix these", the tasks,
     * the feedback a coding agent reads and the reply it answers with — so the list
     * and the finding's own comment say it too. Without it a reader has to match
     * a comment to its line in "Fix these" by path and title.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function heading(array $finding): string
    {
        $id = trim((string) ($finding['id'] ?? ''));

        return ($id === '' ? '' : $id.' · ').$this->label($finding);
    }

    /**
     * Whether the review established this finding, rather than suspecting it.
     *
     * A finding that states no confidence is taken as established — which is what
     * every finding written before the field existed was.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function confident(array $finding): bool
    {
        $confidence = $finding['confidence'] ?? null;

        return ! is_numeric($confidence) || (float) $confidence >= self::CONFIDENT;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    protected function severity(array $finding): string
    {
        $severity = strtoupper((string) ($finding['severity'] ?? ''));

        return in_array($severity, self::SEVERITIES, true) ? $severity : 'LOW';
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    protected function title(array $finding): string
    {
        $title = trim((string) ($finding['title'] ?? ''));

        return $title === '' ? 'Untitled finding' : Str::limit($title, self::MAX_TITLE);
    }

    /**
     * A finding's details, capped.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function details(array $finding): string
    {
        return Str::limit(trim((string) ($finding['details'] ?? '')), self::MAX_DETAILS);
    }

    /**
     * How the review established a finding: what it ran, the lines it read, the
     * guard it checked. Capped like the details.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function evidence(array $finding): string
    {
        $evidence = $finding['evidence'] ?? null;

        return is_string($evidence) ? Str::limit(trim($evidence), self::MAX_DETAILS) : '';
    }

    /**
     * `path:line`, or just the path when there is no line.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function location(array $finding): string
    {
        $path = trim((string) ($finding['path'] ?? ''));

        if ($path === '') {
            return 'unknown';
        }

        return isset($finding['line']) && is_numeric($finding['line'])
            ? $path.':'.(int) $finding['line']
            : $path;
    }

    /**
     * The first sentence of the details, as context on a "fix these" line.
     *
     * @param  array<string, mixed>  $finding
     */
    protected function firstSentence(array $finding): string
    {
        $details = $this->details($finding);

        if ($details === '') {
            return '';
        }

        // Cut at the first line break too: details may run to a second paragraph,
        // and a newline in here would break the numbered list it sits in.
        $sentence = Str::before(Str::before($details, "\n"), '. ');

        return ' '.rtrim($sentence, '.').'.';
    }

    /**
     * Content in a fenced block, with a fence long enough to hold it.
     *
     * The text inside is written by the review and is often *about* markdown — a
     * prompt saying to add a suggestion block, a finding quoting a fenced example.
     * A three-backtick fence round a body containing three backticks ends early, and
     * everything after it, the state markers included, spills into the page as
     * prose. Markdown's own answer is a longer fence, so count what is in there and
     * open with one more.
     */
    public static function fenced(string $content, string $info = ''): string
    {
        preg_match_all('/`++/', $content, $runs);

        $longest = 0;

        foreach ($runs[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$info."\n".$content."\n".$fence;
    }

    protected function shortCommit(?string $commit): string
    {
        return $commit === null || $commit === '' ? 'unknown' : substr($commit, 0, 8);
    }

    /**
     * A commit as something worth clicking: a link to it, and what it was.
     *
     * The sha alone tells a reader nothing they can act on - they have to go and look
     * it up to find out whether they recognise it. The subject line usually tells
     * them immediately, and the link saves the lookup either way.
     *
     * Everything past the link is decoration: if the commit cannot be read, the link
     * still stands on its own.
     */
    protected function describeCommit(Repository $repository, ?string $commit): string
    {
        if ($commit === null || $commit === '') {
            return '`unknown`';
        }

        $short = $this->shortCommit($commit);
        $link = '[`'.$short.'`]('.$repository->sourceControl()->commitUrl($commit).')';

        if (! array_key_exists($commit, $this->commits)) {
            try {
                $this->commits[$commit] = $repository->sourceControl()->getCommit($commit) ?? false;
            } catch (Throwable $exception) {
                report($exception);
                $this->commits[$commit] = false;
            }
        }

        $details = $this->commits[$commit];

        if ($details === false) {
            return $link;
        }

        $message = Str::limit($details['message'], 80);
        $author = $details['author'];

        return $link
            .($message === '' ? '' : ' — '.$message)
            .($author === '' ? '' : ' ('.$author.')');
    }
}
