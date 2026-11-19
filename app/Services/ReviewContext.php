<?php

namespace App\Services;

use App\Models\Repository;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * What a run needs to decide whether to review a pull request, and to review
 * it without fetching anything itself.
 *
 * The AI review leaves markers in its own comments: `ai-review reviewed=<sha>` in
 * its summary and `ai-finding id=… status=…` on each finding. Reading those here
 * keeps the run from spending model turns on it.
 */
class ReviewContext
{
    /**
     * The review's own markers, which it writes as Markdown link-reference
     * definitions on a line of their own.
     *
     * Anchored to the start of a line on purpose: quoting one of the review's
     * comments in a reply copies the marker with a `> ` in front of it, and an
     * unanchored match would read that quote as the review's own state — skipping
     * the next run, or marking a finding fixed that nobody fixed.
     *
     * One prefix for every agent. A review is a review: whichever agent runs next
     * reads the state the last one left and carries its findings forward, rather
     * than opening a thread of its own.
     */
    public const REVIEW_MARKER = '/^\[\/\/\]:\s*#\s*\(ai-review reviewed=(?<commit>[0-9a-f]{7,40})/m';

    /**
     * When the review last published, written into its own marker because the
     * description it lives in has no timestamp of its own.
     */
    public const REVIEW_AT = '/^\[\/\/\]:\s*#\s*\(ai-review .*?\bat=(?<at>[0-9T:+\-\.Z]+)/m';

    public const FINDING_MARKER = '/^\[\/\/\]:\s*#\s*\(ai-finding (?<attributes>[^)\n]+)/m';

    /**
     * The review's replies in a finding's thread — "Fixed in …", "Acknowledged".
     *
     * They carry no finding state, so they cannot use the finding marker without
     * giving one finding two sources. They still need a marker of their own: an
     * unmarked comment reads as a person talking, which counts as activity since
     * the last review and sends the next run off to review a commit it has already
     * reviewed. Editing a description or approving a pull request starts a build,
     * and that build then found its own reply waiting for it.
     */
    public const REPLY_MARKER = '/^\[\/\/\]:\s*#\s*\(ai-review-reply\b/m';

    /**
     * The review's own section of a pull request description, markers included.
     *
     * Read here rather than in {@see ReviewPublisher} because reading the markers
     * back is this class's job, and both sides have to agree on where the block
     * begins and ends.
     */
    public const DESCRIPTION_BLOCK = '/\n*\[\/\/\]:\s*#\s*\(ai-review-start\).*?\[\/\/\]:\s*#\s*\(ai-review-end\)\n*/s';

    /**
     * @param  array<string, mixed>  $pullRequest
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<string, mixed>
     */
    public static function build(
        Repository $repository,
        array $pullRequest,
        array $comments,
        ?string $commit = null,
        bool $reviewDrafts = false,
    ): array {
        // Deleted once, here, so the decision, the findings and the comment list all
        // see the same pull request. Filtering only the list meant deleting the
        // review's own summary did not force a fresh review, and left findings
        // pointing at comments that no longer exist.
        $comments = array_values(array_filter(
            $comments,
            fn (array $comment): bool => ! ($comment['deleted'] ?? false),
        ));

        // Bitbucket does not promise an order, and "the newest marker wins" depends
        // on one, so put them in the order they were written.
        usort($comments, fn (array $a, array $b): int => self::commentDate($a)->getTimestamp() <=> self::commentDate($b)->getTimestamp());

        // The review writes itself into the pull request's description now, between
        // its own markers, so that is the first place to look for what it last did.
        // A summary comment is still read behind it, for pull requests reviewed
        // before the move.
        $description = (string) ($pullRequest['description'] ?? '');
        $summary = self::summaryComment($comments);

        $reviewedCommit = self::reviewedCommit($description)
            ?? ($summary ? self::reviewedCommit((string) ($summary['content']['raw'] ?? '')) : null);

        $reviewedAt = self::reviewedAt($description)
            ?? ($summary ? self::commentDate($summary, 'updated_on') : null);

        $context = [
            'repository' => $repository->workspace.'/'.$repository->slug,
            'pull_request' => [
                'id' => $pullRequest['id'] ?? null,
                'title' => $pullRequest['title'] ?? null,
                'description' => $pullRequest['description'] ?? null,
                'state' => $pullRequest['state'] ?? null,
                'draft' => (bool) ($pullRequest['draft'] ?? false),
                'source_branch' => $pullRequest['source']['branch']['name'] ?? null,
                'destination_branch' => $pullRequest['destination']['branch']['name'] ?? null,
                'head_commit' => $pullRequest['source']['commit']['hash'] ?? null,
                'updated_on' => $pullRequest['updated_on'] ?? null,
            ],
            // Filled in by the controller, but only when the review actually runs:
            // a skipped review has no use for the ticket.
            'ticket' => null,
            'last_reviewed_commit' => $reviewedCommit,
            // Not conditional on the summary: in comment mode the findings live on
            // their own comments, and an edit that loses the summary's marker would
            // otherwise drop every finding and re-report them all as new.
            // The description carries a marker per finding in every mode but
            // `comment`, and is the only carrier in `report` mode. Read first so a
            // finding's own comment still wins for which comment it is and whether
            // that thread is resolved.
            'findings' => self::findings($comments, $description),
            // What the last review said this pull request does, and what has to happen
            // around it. Carried in so a follow-up adds to them rather than replacing
            // them with a description of the newest commit.
            'changes' => self::descriptionList($description, 'Biggest changes'),
            'before_release' => self::descriptionList($description, 'Before release'),
            // What the last review left for a person to check, to keep or to drop.
            'manual_review' => self::descriptionList($description, ReviewPublisher::MANUAL_REVIEW_HEADING),
            'comments' => $conversation = self::compactComments($comments, $reviewedAt),
            // Somebody said something the last review has not seen. Recorded rather
            // than acted on here: whether it is worth reviewing the same commit again
            // depends on who started this run, which only the caller knows.
            // A question for the reviewer is answered where it was asked. It says
            // nothing about the change, so it is no reason to review the commit again.
            'new_activity' => collect($conversation)
                ->contains(fn (array $comment): bool => $comment['after_last_review'] && ! $comment['is_review'] && ! ReviewQuestions::isQuestion($comment['text'])),
            'skip' => false,
            'reason' => null,
        ];

        // A `/review` comment since the last review asks for this draft by name.
        // Once that review has run the comment is older than it, and the draft is
        // left alone again.
        $requested = collect($conversation)
            ->contains(fn (array $comment): bool => $comment['after_last_review'] && ! $comment['is_review'] && ReviewRequest::isRequest($comment['text']));

        if ($context['pull_request']['draft'] && ! $reviewDrafts && ! $requested) {
            $context['skip'] = true;
            $context['reason'] = 'draft';

            return $context;
        }

        // Someone pushed again while this build was queuing, so it is about to spend
        // minutes reviewing code that is no longer the pull request. The newer build
        // reviews the newer commit; this one has nothing to add.
        //
        // Bitbucket sends an abbreviated hash here, so the comparison is by prefix —
        // and an unknown head never skips anything.
        $head = $context['pull_request']['head_commit'];

        if ($commit !== null && is_string($head) && $head !== '' && ! self::isSameCommit($commit, $head)) {
            $context['skip'] = true;
            $context['reason'] = 'superseded';

            return $context;
        }

        // The commit, and nothing else. A pull request being edited or approved
        // starts a build, and the review edits and approves at the end of every run,
        // so the question a build has to answer first is whether this commit has been
        // reviewed — not whether anything has happened since.
        if ($commit && $reviewedCommit === $commit) {
            $context['skip'] = true;
            $context['reason'] = 'already-reviewed';
        }

        return $context;
    }

    /**
     * A hash of everything the review's output depends on.
     *
     * Two runs with the same hash would read the same code, the same instructions
     * and the same conversation, so the second cannot say anything the first did
     * not. What goes in is deliberate — and what stays out more so:
     *
     *  - in: the commit, the pull request's own text, the ticket, the settings that
     *    decide what gets published, the model, every comment a person wrote, and
     *    the version of the review itself and of the instructions the server gave it,
     *    so improving either re-reviews rather than silently reusing an answer
     *    from the old one;
     *  - out: anything the review produced. The findings, the previous summary and
     *    `last_reviewed_commit` all change *because* a review ran, so including
     *    them would make every hash unique and the check useless. The review's own
     *    section of the description is out for that reason too: it is now the place
     *    the review writes, so hashing the description whole meant every run
     *    guaranteed the next one a hash nothing had ever answered.
     *
     * @param  array<string, mixed>  $context
     */
    public static function inputHash(array $context, ?string $ticketMarkdown = null, ?string $recipe = null, ?string $instructions = null): string
    {
        $pullRequest = $context['pull_request'] ?? [];

        /** @var array<int, array<string, mixed>> $allComments */
        $allComments = $context['comments'] ?? [];

        $comments = collect($allComments)
            ->reject(fn (array $comment): bool => (bool) ($comment['is_review'] ?? false))
            ->map(fn (array $comment): string => ($comment['id'] ?? '').':'.($comment['text'] ?? ''))
            ->sort()
            ->values()
            ->all();

        $settings = $context['settings'] ?? [];

        return hash('sha256', json_encode([
            'commit' => $context['settings']['commit'] ?? null,
            'title' => $pullRequest['title'] ?? null,
            'description' => self::withoutReviewBlock($pullRequest['description'] ?? null),
            'source_branch' => $pullRequest['source_branch'] ?? null,
            'destination_branch' => $pullRequest['destination_branch'] ?? null,
            'ticket' => $ticketMarkdown,
            'comments' => $comments,
            'mode' => $settings['mode'] ?? null,
            'fail_on' => $settings['fail_on'] ?? null,
            // The model comes from a variable now, so the recipe hash no longer
            // moves when it changes — it has to be hashed in its own right, or
            // switching model would reuse the previous model's answers.
            'model' => $settings['model'] ?? null,
            'create_tasks' => $settings['create_tasks'] ?? null,
            'recipe' => $recipe,
        ]
            // Only when the server gave the run instructions, so a run that reads
            // the skill's own copy hashes as it always did.
            + ($instructions === null ? [] : ['instructions' => $instructions]), JSON_THROW_ON_ERROR));
    }

    /**
     * A bulleted list under one of the review's own headings in a description.
     *
     * These describe the whole pull request, not the run that last wrote them — so a
     * follow-up review, which only looks at the commits since the last one, has to be
     * given what is already there or it will replace the lot with a description of
     * the newest commit alone.
     *
     * @return array<int, string>
     */
    public static function descriptionList(string $description, string $heading): array
    {
        if ($description === '') {
            return [];
        }

        $items = [];
        $inside = false;

        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#')) {
                // A heading ends the previous list and may start this one.
                $inside = rtrim(ltrim($trimmed, '# ')) === $heading;

                continue;
            }

            if (! $inside) {
                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                $items[] = trim(substr($trimmed, 2));
            } elseif ($trimmed !== '') {
                // Prose under the heading means the list has ended.
                break;
            }
        }

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * A description with the review's own section taken out — what a person wrote.
     *
     * Everything between the markers is the last review talking, so it says nothing
     * about whether this one is needed.
     */
    public static function withoutReviewBlock(?string $description): string
    {
        if ($description === null || $description === '') {
            return '';
        }

        return trim((string) preg_replace(self::DESCRIPTION_BLOCK, "\n", $description));
    }

    /**
     * A description as its author wrote it: without the review's section, the rule
     * the server puts above that section, or the placeholder it writes when there was
     * no description at all ({@see ReviewPublisher::spliceBlock()}).
     */
    public static function authorDescription(string $description): string
    {
        // The rule only where the server put it, directly above the review's section;
        // one the author wrote elsewhere is theirs.
        $description = (string) preg_replace('/(^|\n)[ \t]*---[ \t]*\n+(?=\[\/\/\]:\s*#\s*\(ai-review-start\))/', '$1', $description);
        $description = self::withoutReviewBlock($description);

        return trim(str_replace(ReviewPublisher::DESCRIPTION_PLACEHOLDER, '', $description));
    }

    /**
     * Whether two commit references point at the same commit. Bitbucket abbreviates
     * the hash in some payloads and not others, so the shorter one decides.
     */
    public static function isSameCommit(string $a, string $b): bool
    {
        $length = min(strlen($a), strlen($b));

        return $length >= 7 && strncmp($a, $b, $length) === 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<string, mixed>|null
     */
    protected static function summaryComment(array $comments): ?array
    {
        foreach (array_reverse($comments) as $comment) {
            if (preg_match(self::REVIEW_MARKER, $comment['content']['raw'] ?? '')) {
                return $comment;
            }
        }

        return null;
    }

    /**
     * When a comment was written. Bitbucket always sends these, but a malformed or
     * missing date must not turn into a 500 on the review's critical path, so an
     * unreadable one counts as the beginning of time rather than as "now" — that way
     * it can never make a real comment look older than the last review.
     *
     * @param  array<string, mixed>  $comment
     */
    protected static function commentDate(array $comment, string $field = 'created_on'): Carbon
    {
        $value = $comment[$field] ?? $comment['created_on'] ?? null;

        if (! is_string($value) || $value === '') {
            return Carbon::createFromTimestamp(0);
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return Carbon::createFromTimestamp(0);
        }
    }

    /**
     * The commit the review says it got to.
     *
     * The *last* marker in the comment, not the first. The body above the markers is
     * written by a model - the summary prose, a finding's details - and a review of a
     * repository that documents these markers will quote one sooner or later. Ours
     * are always appended at the end, after everything the model wrote, so reading
     * the last one means quoted text can never be mistaken for the review's own
     * state. Reading the first one meant a quoted marker could make the next run skip
     * a commit as already-reviewed.
     *
     * Finding markers need no equivalent: {@see self::findings()} already keeps the
     * last marker per id, and ours come last for the same reason.
     */
    protected static function reviewedCommit(string $raw): ?string
    {
        if ($raw === '' || ! preg_match_all(self::REVIEW_MARKER, $raw, $matches, PREG_SET_ORDER)) {
            return null;
        }

        return end($matches)['commit'];
    }

    /**
     * When the review last wrote its block, from the `at=` field of its marker.
     *
     * A comment carries its own timestamp; a description does not, so the review
     * writes one. Without it there is no telling which comments in the conversation
     * arrived after the last review, and every one of them would read as new.
     */
    protected static function reviewedAt(string $raw): ?Carbon
    {
        if ($raw === '' || ! preg_match_all(self::REVIEW_AT, $raw, $matches, PREG_SET_ORDER)) {
            return null;
        }

        try {
            return Carbon::parse(end($matches)['at']);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Every finding the review left a marker for, newest state per id.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>
     */
    protected static function findings(array $comments, string $description = ''): array
    {
        $findings = [];

        // A comment carrying the review marker is a summary from before the review
        // moved into the description. It is not read at all: it says what it said on
        // the day it was written, and letting a stale one through would roll a
        // finding's status back to whatever it was then.
        $sources = array_values(array_filter(
            $comments,
            static fn (array $comment): bool => ! preg_match(self::REVIEW_MARKER, (string) ($comment['content']['raw'] ?? '')),
        ));

        // The description last, because it is written after the finding comments in
        // a run and so carries the newest status. It is not any finding's own
        // thread, so it takes the attributes and leaves the comment id and the
        // resolution to whichever comment actually is.
        if ($description !== '') {
            $sources[] = ['id' => null, 'content' => ['raw' => $description], 'is_description' => true];
        }

        foreach ($sources as $comment) {
            preg_match_all(self::FINDING_MARKER, $comment['content']['raw'] ?? '', $matches, PREG_SET_ORDER);

            $isSummary = (bool) ($comment['is_description'] ?? false);

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

                $previous = $findings[$finding['id']] ?? null;

                $finding['comment_id'] = $isSummary && $previous !== null
                    ? $previous['comment_id']
                    : ($comment['id'] ?? null);

                $finding['resolved'] = $isSummary && $previous !== null
                    ? $previous['resolved']
                    : ($comment['resolution'] ?? null) !== null;

                $findings[$finding['id']] = $finding;
            }
        }

        return array_values($findings);
    }

    /**
     * The comments, without the API's envelope: who said what, where, and whether
     * it arrived after the last review.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>
     */
    protected static function compactComments(array $comments, ?Carbon $reviewedAt): array
    {
        return collect($comments)
            ->reject(fn (array $comment): bool => (bool) ($comment['deleted'] ?? false))
            ->map(function (array $comment) use ($reviewedAt): array {
                $raw = (string) ($comment['content']['raw'] ?? '');
                $createdAt = self::commentDate($comment);

                return [
                    'id' => $comment['id'] ?? null,
                    'parent_id' => $comment['parent']['id'] ?? null,
                    'author' => $comment['user']['display_name'] ?? null,
                    'created_on' => $createdAt->toIso8601String(),
                    'path' => $comment['inline']['path'] ?? null,
                    'line' => $comment['inline']['to'] ?? $comment['inline']['from'] ?? null,
                    // The same anchoring as the markers themselves: a reply that
                    // quotes the review is a person talking, not the review's own
                    // comment, and it has to count as activity since the last run.
                    'is_review' => (bool) (
                        preg_match(self::REVIEW_MARKER, $raw)
                        || preg_match(self::FINDING_MARKER, $raw)
                        || preg_match(self::REPLY_MARKER, $raw)
                    ),
                    // Resolving a thread is how someone says "seen, leave it" without
                    // writing a reply. Without this the next review cannot tell, and
                    // re-raises a finding that was deliberately settled.
                    'resolved' => ($comment['resolution'] ?? null) !== null,
                    'after_last_review' => $reviewedAt === null || $createdAt->greaterThan($reviewedAt),
                    'text' => $raw,
                ];
            })
            ->values()
            ->all();
    }
}
