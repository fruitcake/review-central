<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Tickets\TicketResolver;
use Throwable;

/**
 * The start of a review of one pull request: whether to run at all, and everything
 * the reviewer reads, with the pull request and its comments in a compact shape.
 *
 * Asking for it is a run saying "I am about to review this": it takes the review
 * lock and opens the run's record. Both runners ask here: the container review's
 * precheck and the diff review.
 */
class ReviewOpening
{
    public function __construct(protected TicketResolver $tickets) {}

    /**
     * @param  array<string, mixed>  $input  the pull request, commit, build and reviewer the run is for
     * @return array{status: int, body: array<string, mixed>} the answer, with an HTTP-like status: 409 when another run holds the lock
     */
    public function open(Repository $repository, array $input): array
    {
        $input['stack'] = array_key_exists('stack', $input)
            ? array_values($input['stack'] ?? [])
            : ReviewStack::resolve($repository, $input['review_stack'] ?? null);

        $id = isset($input['pull_request_id']) ? (string) $input['pull_request_id'] : null;

        // A run is started for a branch, and may not know its pull request yet, so
        // it may ask by branch instead of by id.
        if ($id === null) {
            $branch = $input['branch'] ?? null;

            if (! is_string($branch) || $branch === '') {
                return ['status' => 422, 'body' => ['message' => 'Pass a pull_request_id or a branch.']];
            }

            $pullRequest = $repository->sourceControl()->findOpenPullRequestByBranch($branch);

            if ($pullRequest === null) {
                return ['status' => 200, 'body' => [
                    'skip' => true,
                    'reason' => 'no-open-pull-request',
                    'branch' => $branch,
                ]];
            }

            $id = (string) $pullRequest['id'];
        } else {
            $pullRequest = $repository->sourceControl()->getPullRequest($id);

            if ($pullRequest === null) {
                return ['status' => 404, 'body' => ['message' => 'There is no such pull request.']];
            }
        }

        $commit = ($input['commit'] ?? '') !== '' ? $input['commit'] : null;

        $comments = $repository->sourceControl()->getPullRequestComments($id);

        $context = ReviewContext::build(
            $repository,
            $pullRequest,
            $comments,
            $commit,
            (bool) ($input['drafts'] ?? false),
        );

        // "Reviewed up to <commit>" is read from a marker in the description or in a
        // comment, and the author of a pull request can write one as well as the
        // review can. One that names a commit no review of this pull request
        // finished on is not believed: the commit is reviewed, and in full.
        $claimed = $context['last_reviewed_commit'] ?? null;

        if (is_string($claimed) && ! $this->reviewed($repository, $context['pull_request']['id'] ?? null, $claimed)) {
            $context['last_reviewed_commit'] = null;

            if ($context['reason'] === 'already-reviewed') {
                $context['skip'] = false;
                $context['reason'] = null;
            }
        }

        // A run started by hand on a commit that has already been reviewed - a
        // second agent, or the same one after the skill changed. Only the two
        // "nothing has changed" reasons are overridden: a draft is still a draft,
        // and a superseded commit is still not the pull request.
        $forced = (bool) ($input['force'] ?? false);

        if ($forced && $context['skip'] && $context['reason'] === 'already-reviewed') {
            $context['skip'] = false;
            $context['reason'] = null;
        }

        // A merged pull request has no newer commit coming. A run that is kept off
        // it may review what went in, which after a squash is not the branch's head.
        if (($input['custom'] ?? false) && $context['reason'] === 'superseded' && ($pullRequest['state'] ?? null) === 'MERGED') {
            $context['skip'] = false;
            $context['reason'] = null;
        }

        $holder = ($input['lock'] ?? '') !== '' ? $input['lock'] : null;
        $build = ($input['build'] ?? '') !== '' ? $input['build'] : $holder;

        // Somebody has said something the last review never saw, so the same commit
        // is worth reading again against what they said. However the build started:
        // a person replying to a finding is the same reason whether they then press
        // Run or leave it to the next trigger.
        //
        // What this must not answer is the review's own work. The review approves the
        // pull request and writes its summary into the description at the end of every
        // run, and both of those start another build — so its replies carry a marker
        // and its section of the description is read as its own. `new_activity` is
        // what is left once those are discounted: people talking.
        if (! $forced && $context['reason'] === 'already-reviewed' && ($context['new_activity'] ?? false)) {
            $context['skip'] = false;
            $context['reason'] = null;
        }

        // The lock is keyed on the branch the pull request comes from, taken from
        // the pull request itself rather than the request, so it is the same key
        // whether the caller asked by id or by branch.
        $lockBranch = $context['pull_request']['source_branch'] ?? null;

        // The ticket is part of what the review reads, so it is part of the hash —
        // and a changed acceptance criterion has to re-review even on the same commit.
        $ticket = $context['skip'] ? null : $this->tickets->forTitle($repository, $context['pull_request']['title']);

        $instructions = $this->instructions($repository, $input);

        $inputHash = ReviewContext::inputHash(
            $context + ['settings' => [
                'commit' => $commit,
                'mode' => $input['mode'] ?? null,
                'fail_on' => $input['fail_on'] ?? null,
                'model' => $input['model'] ?? null,
                // Absent stays null rather than becoming false: a caller that does
                // not send it must hash the same as it always did.
                'create_tasks' => $input['create_tasks'] ?? null,
            ]],
            $ticket['markdown'] ?? null,
            $input['recipe'] ?? null,
            // Changing what the review is told re-reviews, as changing the skill does.
            $instructions['version'] ?? null,
        );

        // Nothing that decides the review has changed since a run that finished, so
        // that run's answer is still the answer.
        if (! $forced && ! $context['skip'] && $this->alreadyAnswered($repository, $inputHash)) {
            $context['skip'] = true;
            $context['reason'] = 'unchanged';
        }

        // A probe answers only the question the run asks before it installs
        // anything: run this review or not. It skips the ticket lookup and the
        // comment bodies, so the precheck step stays a fast, tiny HTTP call; the
        // review step asks for the whole context once it is worth fetching.
        //
        // It reads the lock but never takes it: the run that takes the lock has to
        // be the run that will release it, and a precheck step cannot promise that
        // — the review step after it may be skipped or never start.
        if ($input['probe'] ?? false) {
            $lockedBy = $lockBranch ? ReviewLock::heldBy($repository, $lockBranch) : null;
            $heldByAnother = $lockedBy !== null && $lockedBy !== $holder;

            return ['status' => 200, 'body' => [
                'skip' => $context['skip'] || $heldByAnother,
                'reason' => $heldByAnother && ! $context['skip'] ? 'in-progress' : $context['reason'],
                'last_reviewed_commit' => $context['last_reviewed_commit'],
                'locked_by' => $lockedBy,
                'input_hash' => $inputHash,
            ]];
        }

        // Asking for the full context is a run saying "I am about to review this".
        // Only one run at a time gets to say that for a given commit, and a run that
        // loses the race is told so with a status it cannot mistake for anything
        // else: 409, the same answer it would get for any other lost race.
        if (! $context['skip'] && $holder !== null && $commit !== null && $lockBranch !== null) {
            if (! ReviewLock::acquire($repository, $lockBranch, $holder)) {
                $lockedBy = ReviewLock::heldBy($repository, $lockBranch);

                // A run that was killed never released the lock. Only one its
                // runner says is gone loses it: "cannot tell" keeps the lock,
                // because reviewing twice is the lesser harm than two agents
                // commenting on the same pull request at once.
                if ($lockedBy !== null && $this->buildIsGone($repository, $lockedBy)) {
                    ReviewLock::takeOver($repository, $lockBranch, $holder);
                    $this->abandonRun($repository, $lockBranch, $commit, $lockedBy);
                } else {
                    // Turned away, not discarded: this commit still needs reviewing,
                    // and the run that holds the lock cannot see it. Recorded so the
                    // newest one can be started again when the lock frees up.
                    $this->recordPending($repository, $context, $lockBranch, $commit, $holder, $inputHash, $input);

                    return ['status' => 409, 'body' => [
                        'skip' => true,
                        'reason' => 'in-progress',
                        'locked_by' => $lockedBy,
                        'pending' => true,
                    ]];
                }
            }
        }

        // What the author wrote, never the last review's own section: the review
        // reads this as the author's account of the change. What the last review
        // said reaches it as findings and markers instead. After the hash, which
        // strips the section itself and must not move.
        if (is_string($context['pull_request']['description'] ?? null)) {
            $context['pull_request']['description'] = ReviewContext::authorDescription($context['pull_request']['description']);
        }

        $context['findings'] = $this->enrichFindings($repository, $context['pull_request']['id'] ?? null, $context['findings']);
        $context['ticket'] = $ticket;
        $context['input_hash'] = $inputHash;
        // Only to a run that reviews: a skipped one exits before it reads anything.
        $context['instructions'] = $context['skip'] ? null : $instructions;
        // The stacks those instructions were chosen for, which the review step reads
        // as settings.stack.
        $context['stack'] = $input['stack'];
        // A previous run published a review and said the pull request had already
        // moved past it. This is that handover: the commit it did not get to, and
        // the one it reviewed up to, so this run can pick up where it left off
        // rather than be assumed to have noticed.
        $context['handover'] = $commit === null ? null : $this->handover($repository, $commit);

        // Being handed the context is the moment a run commits to reviewing, so that
        // is where its record starts — lock or no lock, since a comparison run is
        // still a run whose cost should be visible. The report step closes it; the
        // hourly sweep closes the ones that never come back.
        if (! $context['skip'] && $build !== null && $commit !== null && $lockBranch !== null) {
            // The bodies go into the version, once, rather than into every run's
            // copy of the context: tens of kilobytes of instructions each.
            $stored = $context;

            if ($instructions !== null) {
                ReviewInstructions::store($instructions['files']);
                $stored['instructions']['files'] = array_map(fn (array $file): array => array_diff_key($file, ['body' => true]), $instructions['files']);
            }

            $this->openRun($repository, $stored, $lockBranch, $commit, $build, $inputHash, $input, $instructions['version'] ?? null);
        }

        return ['status' => 200, 'body' => $context];
    }

    /**
     * The instructions this run reads, when it asks for them under a contract the
     * server writes them for and the server has any. Null leaves the review to
     * the copy the skill ships with.
     *
     * @param  array<string, mixed>  $input
     * @return array{version: string, contract: string, files: array<int, array{key: string, title: string, stack: string|null, file: string, body: string}>}|null
     */
    protected function instructions(Repository $repository, array $input): ?array
    {
        if (($input['instructions'] ?? null) !== ReviewInstructions::CONTRACT) {
            return null;
        }

        $files = ReviewInstructions::files($repository->tenant, array_values($input['stack'] ?? []));

        return $files === null ? null : [
            'version' => ReviewInstructions::version($files),
            'contract' => ReviewInstructions::CONTRACT,
            'files' => $files,
        ];
    }

    /**
     * Opens the record for this run, or reuses the one a retried step already made.
     *
     * @param  array<string, mixed>  $context
     * @param  array{agent?: string|null, provider?: string|null, effort?: string|null, advisor?: string|null, custom?: bool|null}  $reviewer
     */
    protected function openRun(Repository $repository, array $context, string $branch, string $commit, string $build, string $inputHash, array $reviewer, ?string $instructionsVersion): void
    {
        $this->adoptQueued($repository, $commit, ctype_digit($build) ? (int) $build : null);

        ReviewRun::updateOrCreate(
            [
                'repository_id' => $repository->getKey(),
                'commit' => $commit,
                'build_number' => ctype_digit($build) ? (int) $build : null,
            ],
            [
                'tenant_id' => $repository->tenant_id,
                'input_hash' => $inputHash,
                'workspace' => $repository->workspace,
                'slug' => $repository->slug,
                'branch' => $branch,
                'pull_request_id' => $context['pull_request']['id'] ?? null,
                'status' => ReviewRun::STATUS_RUNNING,
                'runner' => $this->runner($repository, $reviewer),
                'context' => $context,
                'agent' => $reviewer['agent'] ?? null,
                'provider' => $reviewer['provider'] ?? null,
                'effort' => $reviewer['effort'] ?? null,
                'advisor' => $reviewer['advisor'] ?? null,
                'custom' => (bool) ($reviewer['custom'] ?? false),
                'instructions_version' => $instructionsVersion,
                'started_at' => now(),
            ],
        );
    }

    /**
     * Whether a review of this pull request that was published finished on a commit.
     */
    protected function reviewed(Repository $repository, mixed $pullRequestId, string $commit): bool
    {
        if (! is_numeric($pullRequestId)) {
            return false;
        }

        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', (int) $pullRequestId)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->where('commit', 'like', substr($commit, 0, 7).'%')
            ->pluck('commit')
            ->contains(fn (string $reviewed): bool => ReviewContext::isSameCommit($reviewed, $commit));
    }

    /**
     * The record a run was given when it was started, before it began: it is this
     * run's from here on. It was made from the pull request's head as the host
     * named it then, so it takes the commit this run names.
     */
    protected function adoptQueued(Repository $repository, string $commit, ?int $build): void
    {
        if ($build === null) {
            return;
        }

        ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->where('status', ReviewRun::STATUS_QUEUED)
            ->update(['commit' => $commit]);
    }

    /**
     * Fills in what a marker cannot carry.
     *
     * An `ai-finding` marker holds an id, a status, a severity and a place. What
     * the finding actually claimed — its title, its detail, its category and the
     * source line it rests on — lives in the comment body, which a follow-up would
     * otherwise have to re-read and re-derive. The server already stored all of it when
     * the run reported, so it is handed back instead. The source it rests on - the
     * `anchor` line and the `existing_code` around it, up to `end_line` - is what
     * lets a follow-up tell whether the code a finding was about is still there,
     * rather than guess from line numbers.
     *
     * Only this pull request's runs: finding ids start again at F1 on every pull
     * request, so another one's F1 says nothing about this one's. And per finding,
     * the newest run that has it, not only the newest run: a finding the latest run
     * left out is still the one an earlier run described.
     *
     * The marker wins on status: it reflects edits made since, and a stored status
     * is only ever as fresh as the run that wrote it.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    protected function enrichFindings(Repository $repository, mixed $pullRequestId, array $findings): array
    {
        if ($findings === [] || ! is_numeric($pullRequestId)) {
            return $findings;
        }

        $byId = [];

        ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', (int) $pullRequestId)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->whereNotNull('findings')
            ->latest('finished_at')
            ->limit(20)
            ->pluck('findings')
            ->each(function (mixed $stored) use (&$byId): void {
                foreach (is_array($stored) ? $stored : [] as $finding) {
                    if (is_array($finding) && isset($finding['id'])) {
                        $byId[(string) $finding['id']] ??= $finding;
                    }
                }
            });

        return array_map(function (array $finding) use ($byId): array {
            $previous = $byId[(string) ($finding['id'] ?? '')] ?? null;

            if (! is_array($previous)) {
                return $finding;
            }

            foreach (['title', 'details', 'anchor', 'existing_code', 'end_line', 'introduced', 'category'] as $field) {
                if (isset($previous[$field]) && ! isset($finding[$field])) {
                    $finding[$field] = $previous[$field];
                }
            }

            return $finding;
        }, $findings);
    }

    /**
     * The handover left by the last run that published for an earlier commit.
     *
     * @return array<string, mixed>|null
     */
    protected function handover(Repository $repository, string $commit): ?array
    {
        // A connection that posts nothing left no findings on the pull request to
        // carry forward: the next review reviews the whole change, not the new
        // commits alone, or it could approve what an earlier run held.
        if (! rescue(fn (): bool => $repository->sourceControl()->publishes(), true, false)) {
            return null;
        }

        $previous = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->whereNotNull('superseded_by')
            ->where('commit', '!=', $commit)
            ->latest('finished_at')
            ->first();

        if ($previous === null || ! ReviewContext::isSameCommit($previous->superseded_by, $commit)) {
            return null;
        }

        return [
            'reviewed_up_to' => $previous->commit,
            'handed_over_at' => $previous->finished_at?->toIso8601String(),
            'open_findings' => $previous->openFindings(),
        ];
    }

    /**
     * Whether a finished run already answered this exact question.
     */
    protected function alreadyAnswered(Repository $repository, string $inputHash): bool
    {
        return ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('input_hash', $inputHash)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->exists();
    }

    /**
     * The runner this review runs on, which its caller knows: a trial from the diff
     * is not on the runner its repository is set to.
     *
     * @param  array<string, mixed>  $input
     */
    protected function runner(Repository $repository, array $input): string
    {
        return is_string($input['runner'] ?? null) && $input['runner'] !== '' ? $input['runner'] : $repository->runnerName();
    }

    /**
     * Records a run the lock turned away, so it can be started again later.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $input
     */
    protected function recordPending(
        Repository $repository,
        array $context,
        string $branch,
        string $commit,
        string $holder,
        string $inputHash,
        array $input = [],
    ): void {
        $build = ctype_digit($holder) ? (int) $holder : null;

        $this->adoptQueued($repository, $commit, $build);

        ReviewRun::updateOrCreate(
            [
                'repository_id' => $repository->getKey(),
                'commit' => $commit,
                'build_number' => $build,
            ],
            [
                'tenant_id' => $repository->tenant_id,
                'workspace' => $repository->workspace,
                'slug' => $repository->slug,
                'branch' => $branch,
                'pull_request_id' => $context['pull_request']['id'] ?? null,
                'input_hash' => $inputHash,
                'status' => ReviewRun::STATUS_PENDING,
                'runner' => $this->runner($repository, $input),
                'started_at' => now(),
            ],
        );
    }

    /**
     * The run that held the lock is gone, so its record is too: it started and never
     * reported a result.
     */
    protected function abandonRun(Repository $repository, string $branch, string $commit, string $holder): void
    {
        if (! ctype_digit($holder)) {
            return;
        }

        ReviewRun::running()
            ->where('repository_id', $repository->getKey())
            ->where('commit', $commit)
            ->where('build_number', (int) $holder)
            ->get()
            ->each(fn (ReviewRun $run) => $run->finish(ReviewRun::STATUS_FAILED));
    }

    /**
     * Whether the build holding a lock has certainly finished. Anything uncertain —
     * a holder that is not a build number, an API that will not answer — counts as
     * still running, so a lock is never taken away on a guess.
     */
    protected function buildIsGone(Repository $repository, string $holder): bool
    {
        if (! ctype_digit($holder)) {
            return false;
        }

        try {
            return $repository->runner()->isRunning($repository, $holder) === false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
