<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Enums\BuildState;
use App\Exceptions\CheckoutFailed;
use App\Exceptions\ReviewStopped;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\ContainerRunner;
use App\Runners\QueuedRuns;
use App\Runners\RunConclusion;
use App\Services\Broadcasts;
use App\Services\ReviewCredentials;
use App\Services\ReviewStops;
use App\Services\RunMetrics;
use App\SourceControl\GitDriver;
use App\SourceControl\PullRef;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One review on this installation's own worker.
 *
 *  1. The repository is cloned here, on the worker; or, for a connection that asks
 *     for it, by the Cloudflare sandbox itself in step 3, and only its commit
 *     looked up here.
 *  2. The precheck decides whether to review and writes the context, here too.
 *  3. The reviewer reads the code and writes its report, in a container that is
 *     thrown away. It is the only step that sees the pull request's code run, and
 *     it gets the AI provider's key and nothing else: no credential for this
 *     server, none for the repository's host. A sandbox that clones for itself
 *     reaches the host through the Worker, which keeps the credential and adds it
 *     to reading this one repository only.
 *  4. The worker reads that report as data and files it, which posts it on the
 *     pull request.
 *  5. The review lock is released, and the pull request is told how it ended.
 *
 * One container a review, and none for a review that is turned away.
 */
class ContainerReview
{
    /**
     * What the review reads and writes in the checkout, as prepare-review.sh lists it.
     */
    protected const REVIEW_FILES = [
        '/.review/', '/.review-variables', '/.review-skills/', '/.review-instructions/', '/review-context.json', '/review-ticket.md',
        '/ai-review.json', '/ai-usage.json', '/claude-review.jsonl', '/codex-review.jsonl', '/codex-review-candidates.md', '/ocr-review.json', '/pi-review.jsonl',
        '/ocr-background.md', '/ocr-rules.json', '/review-scan.md', '/.review-started', '/.review-verdict', '/.claude-exit', '/.codex-exit', '/.pi-exit',
    ];

    public function __construct(
        protected Checkout $checkout,
        protected Sandbox $sandbox,
        protected ReviewCredentials $credentials,
        protected RunMetrics $metrics,
        protected ContainerRunner $runner,
        protected RunLog $log,
        protected ComposerCache $composerCache,
        protected RunConclusion $conclusion,
        protected QueuedRuns $queued,
        protected ReviewSessions $sessions,
        protected Precheck $precheck,
        protected Report $report,
        protected Sandboxes $sandboxes,
        protected ReviewStops $stops,
    ) {}

    /**
     * @param  int  $rounds  the reviews the job may do, as it was told when it was queued: its time limit was set for that many
     * @param  Trial|null  $trial  a review to try a setup with, which is kept off the pull request
     */
    public function run(Repository $repository, string $branch, ?string $commit, int $build, ?int $waited = null, int $rounds = 1, ?Trial $trial = null): void
    {
        // Docker on this worker or a Cloudflare sandbox: the trial's runner, else the repository's.
        $this->sandbox = $this->sandboxes->for($trial, $repository, $this->sandbox);
        $directory = $this->workDirectory($repository, $build);
        $first = $build;
        // A trial is one review of one commit: it never carries on.
        $followUps = $trial === null ? max(0, $rounds - 1) : 0;
        // The reviewer's home: the one kept for the branch, so this review resumes
        // the session of the last, or one for this job alone when it may carry on
        // and nothing is kept. A trial is no part of the pull request's own reviews:
        // it gets a home of its own, kept for questions about it.
        $session = $trial === null ? $branch : ReviewSessions::forTrial($branch, $build);
        $kept = $this->sessions->home($repository, $session);
        $home = $kept ?? ($followUps > 0 ? $this->homeDirectory($directory) : null);
        $round = 0;

        try {
            File::ensureDirectoryExists(dirname($directory), 0700);
            File::deleteDirectory($directory);

            if ($home !== null && $kept === null) {
                File::deleteDirectory($home);
                File::ensureDirectoryExists($home, 0700);
            }

            if ($followUps > 0) {
                $this->runner->claimBranch($repository, $branch);
            }

            while (true) {
                try {
                    $reported = $this->review($repository, $branch, $commit, $build, $directory, $home, $round, $waited, $trial, carriesOn: $followUps > 0);
                } catch (Throwable $exception) {
                    // A review the job carried on with that broke before it began
                    // (the fetch failed, say) was accepted and has reviewed nothing:
                    // it gets a job of its own, from a fresh clone.
                    if ($round > 0 && $this->queued->waiting($repository, $build)) {
                        report($exception);
                        $this->runner->dispatch($repository, $branch, $commit, $build);

                        break;
                    }

                    $this->queued->close($repository, $build, ReviewRun::STATUS_FAILED, 'The review did not start');

                    throw $exception;
                }

                if ($followUps === 0) {
                    break;
                }

                // Only a review that was published is worth carrying on from, and
                // only so many times: a session over many commits grows stale.
                $carryOn = $reported && $round < $followUps;
                $next = $carryOn ? $this->runner->nextFollowUp($repository, $branch) : null;

                if ($next === null) {
                    break;
                }

                $this->log->writer((int) $repository->getKey(), $build)?->note("Carrying on with build {$next['build']}, for a newer commit, in the same checkout.");
                $this->log->writer((int) $repository->getKey(), $next['build'])?->note("Carried on from build {$build}: the same job and checkout, updated to the newer commit.");

                // It waited for the review before it, not for a worker.
                [$build, $commit, $waited] = [$next['build'], $next['commit'], $next['queued_at'] === null ? null : max(0, time() - $next['queued_at'])];
                $round++;
                $this->runner->claimBranch($repository, $branch);

                // Should the job die in this review, this is the run it leaves behind.
                $this->runner->noteRound($repository, $first, $build, $commit);
            }
        } finally {
            File::deleteDirectory($directory);

            if ($home !== null && $kept === null) {
                File::deleteDirectory($home);
            }

            if ($followUps > 0) {
                $this->runner->forgetRound($repository, $first);
                $this->letGo($repository, $branch);
            }
        }
    }

    /**
     * One review in the job's checkout: the first from a fresh clone, a later one
     * from the branch as it has become.
     *
     * @return bool whether the reviewer finished and its report was filed
     */
    protected function review(Repository $repository, string $branch, ?string $commit, int $build, string $directory, ?string $home, int $round, ?int $waited, ?Trial $trial = null, bool $carriesOn = false): bool
    {
        // Stopped while it waited: closed then, and nothing to do now.
        if (($by = $this->stops->requested($repository, $build)) !== null) {
            $this->log->writer((int) $repository->getKey(), $build)?->note("Stopped by {$by} before it started.");

            return false;
        }

        $this->runner->markRunning($repository, $build);

        $reviewing = false;
        // What the reviewer's step was seen to use, once it ran.
        $used = null;
        // The name of the sandbox kept for the branch, and when it is wanted.
        $warm = null;
        $warmth = ['mode' => CloudflareSandbox::WARM_OFF, 'seconds' => 0];

        try {
            // What a sandbox that clones for itself is to clone. The worker then
            // clones nothing: it only looks up the commit.
            $source = $this->sandboxSource($repository, $branch, $commit, $trial);

            if ($source !== null) {
                $this->log->writer((int) $repository->getKey(), $build)?->note("The sandbox clones the repository itself, at {$source['commit']}.");
                // A later review of the job starts from what the last one left here.
                File::ensureDirectoryExists($directory, 0700);
                File::cleanDirectory($directory);
                $commit = $source['commit'];
            } else {
                $this->log->writer((int) $repository->getKey(), $build)?->note($round > 0 ? '── fetch ──' : '── clone ──');

                // A job whose earlier review was cloned in a sandbox has no clone
                // here to update.
                $update = $round > 0 && File::isDirectory("{$directory}/.git");

                if ($round > 0 && ! $update) {
                    File::deleteDirectory($directory);
                }

                $commit = match (true) {
                    $update => $this->checkout->update($repository, $directory, $branch, $commit),
                    $trial?->mergeCommit !== null => $this->checkout->cloneMerged($repository, $trial->destination, $trial->mergeCommit, $directory)['head'],
                    $trial?->pullRef !== null => $this->checkout->clonePullRef($repository, $trial->destination, $trial->pullRef, $directory),
                    default => $this->checkout->clone($repository, $branch, $commit, $directory),
                };
            }

            $this->takeReviewFiles($directory);

            $pipeline = $this->pipelineEnvironment($repository, $branch, $commit, $build) + ($trial?->environment() ?? []);

            // Decided and gathered here, by the worker: it reads no code, so it needs
            // no container, and a review that is turned away starts none. One that
            // cannot start at all throws, so the job does not end quietly.
            $log = $this->log->writer((int) $repository->getKey(), $build);
            $log?->note('── precheck ──');
            $outcome = $this->precheck->run($repository, $branch, $commit, $build, $directory, $trial, $log, $this->sandbox->name());

            if ($outcome['REVIEW_NEEDED'] !== 'true') {
                // Told there is nothing to review; turned away by the lock, its
                // record is waiting for that by now and is left alone.
                $this->queued->close($repository, $build, ReviewRun::STATUS_SKIPPED, 'Nothing to review');

                return false;
            }

            // A trial shows nothing on the pull request, so it has nothing to end either.
            $reviewing = $trial === null;

            if ($reviewing) {
                $this->status($repository, $commit, $build, BuildState::InProgress, 'Reviewing');
            }

            // The precheck opened the run's record; how long it waited for a worker
            // is only known here.
            if ($waited !== null) {
                ReviewRun::query()
                    ->where('repository_id', $repository->getKey())
                    ->where('commit', $commit)
                    ->where('build_number', $build)
                    ->update(['queue_seconds' => $waited]);
            }

            $destination = $outcome['REVIEW_DESTINATION_BRANCH'];

            // The sandbox fetches the destination beside the commit. It can fetch
            // what it is missing later on, so nothing is compared ahead of it.
            // A merged pull request's is the trial's: the one its base was taken from.
            $clone = $source === null ? null : $this->checkout->forSandbox($repository, $source['ref'], $source['name'], $commit, $source['destination'] ?? ($destination !== '' ? $destination : null), $source['base'], $trial?->mergeCommit === null ? $this->lastReviewedCommit($directory) : null);

            // A merged pull request was compared when it was cloned, with the
            // destination as it was; fetching it now would move it past the merge.
            if ($source === null && $destination !== '' && $trial?->mergeCommit === null) {
                $this->checkout->prepareRange($repository, $directory, $destination, $this->lastReviewedCommit($directory));
            }

            $handover = array_intersect_key($outcome, array_flip(['REVIEW_REVIEWER', 'REVIEW_PR_ID', 'REVIEW_DESTINATION_BRANCH']));

            if ($carriesOn && $source === null) {
                // The reviewer cannot write the git directory when the job may carry
                // on, so what its scripts would note there is noted for them.
                $this->excludeReviewFiles($directory);
            }

            // In the job's own checkout a later review always resumes the one before
            // it. From a fresh clone it resumes the session kept for the branch.
            $resumes = $round > 0 || ($home !== null && $trial === null && $this->sessions->resumes($home));

            // A sandbox that clones is kept for the next review of the branch, which
            // then fetches instead of starting and cloning. Never for a trial.
            $warmth = CloudflareSandbox::warmth();

            if ($clone !== null && $trial === null && $this->sandbox instanceof CloudflareSandbox && $warmth['mode'] !== CloudflareSandbox::WARM_OFF) {
                $warm = CloudflareSandbox::warmName((int) $repository->getKey(), $branch);
                $this->sandbox->keepWarm($warm, $warmth['seconds']);

                // This review takes the branch's sandbox over, or replaces it: the
                // review that left it no longer has one waiting.
                $this->forgetWarm(ReviewRun::query()->where('repository_id', $repository->getKey())->where('branch', $branch));
            }

            $reviewed = $this->step(
                'review',
                $repository,
                $build,
                'export REVIEW_AGENT="$REVIEW_REVIEWER" && . .review/skill/prepare-review.sh && sh .review/skill/run-review.sh && sh .review/skill/collect-usage.sh',
                $directory,
                $pipeline + $handover + $this->credentials->environment($repository->tenant, $handover['REVIEW_REVIEWER'] ?: null)
                    // Picks the reviewer's session up where the last review left it.
                    + ($resumes ? ['REVIEW_CONTINUE' => 'true'] : []),
                trusted: false,
                timeout: (int) config('review.container.timeout'),
                home: $home,
                lockedGit: $carriesOn && $source === null,
                composerCache: $this->composerCache->directory($repository),
                clone: $clone,
            );

            $used = ['samples' => $this->sandbox->samples, 'processors' => $this->sandbox->processors, 'memory_mb' => $this->sandbox->memoryMb];

            // What the reviewer was seen to use at most, with the limit it had then:
            // the limit is a setting, and may be another one by the time someone reads this.
            if ($this->sandbox->peakMemory !== null) {
                ReviewRun::query()
                    ->where('repository_id', $repository->getKey())
                    ->where('commit', $commit)
                    ->where('build_number', $build)
                    ->update([
                        'peak_memory_mb' => (int) ceil($this->sandbox->peakMemory / 1024 ** 2),
                        'memory_limit' => $this->sandbox->memoryMb !== null ? round($this->sandbox->memoryMb / 1024, 2).'g' : (string) config('review.container.memory'),
                    ]);
            }

            // A sandbox that is paid for by the second: where it ran, how long, and
            // what that comes to. Also for a reviewer that failed: it was paid for.
            if ($this->sandbox->seconds !== null) {
                ReviewRun::query()
                    ->where('repository_id', $repository->getKey())
                    ->where('commit', $commit)
                    ->where('build_number', $build)
                    ->update([
                        'sandbox' => $this->sandbox->name(),
                        'sandbox_size' => CloudflareSandbox::instance(),
                        'sandbox_seconds' => $this->sandbox->seconds,
                        'sandbox_cost' => $this->sandbox->cost,
                        'warm_until' => $this->sandbox instanceof CloudflareSandbox ? $this->sandbox->warmUntil : null,
                    ]);
            }

            // A reviewer that failed has no report to file, and
            // releasing the lock below closes the run as failed.
            if (! $reviewed->successful()) {
                // Whether the session it resumed is what broke it cannot be told from
                // here, and a session that cannot be resumed would fail every review
                // of the branch from now on. The next one starts fresh.
                if ($resumes && $round === 0 && $home !== null) {
                    $this->sessions->reset($home);
                }

                return false;
            }

            if ($resumes && $home !== null) {
                $this->sessions->resumed($home);
            }

            // For the next review of the branch, or the questions about a trial, on
            // whichever worker runs them. A home of the job's own is not kept.
            $this->sessions->store($repository, $trial === null ? $branch : ReviewSessions::forTrial($branch, $build));

            // Stopped after the reviewer was done, while its files came back or its
            // session was kept: nothing is filed either. Looked at last, before filing.
            if ($this->stops->requested($repository, $build) !== null) {
                throw new ReviewStopped('The review was stopped after its reviewer finished.');
            }

            // Filed here, by the worker: it reads what the reviewer wrote as data and
            // runs nothing of it, so it needs no container either.
            $log?->note('── report ──');

            return $this->report->run($repository, $branch, $commit, $build, (int) $outcome['REVIEW_PR_ID'], $directory, $trial, $log);
        } catch (ReviewStopped) {
            // Its sandbox is gone; nothing of it is filed or posted.
            $by = $this->stops->requested($repository, $build) ?? 'someone';
            $this->stops->stopped($repository, $build, $by);
            $this->log->writer((int) $repository->getKey(), $build)?->note("Stopped by {$by}.");

            return false;
        } finally {
            // Never in the way of the review: a run without them only has none.
            rescue(fn () => $this->metrics->store($repository, $build, $used), null, false);

            // By the review as it was filed, before the run is concluded.
            if ($warm !== null) {
                $this->coolUnlessWanted($repository, $build, $warm, $warmth['mode']);
            }

            if ($commit !== null) {
                $this->finish($repository, $branch, $commit, $build, $reviewing, $trial !== null);
            }

            $this->runner->markEnded($repository, $build);
        }
    }

    /**
     * What went wrong, for the log: the exception's name, and the message of the
     * one at the bottom of its chain, which says why. A disk's "Unable to write
     * file" alone does not say whether it was the key, the bucket or the size.
     */
    protected static function reason(Throwable $exception): string
    {
        $cause = $exception;

        while ($cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $cause->getMessage()));

        return class_basename($exception).($message === '' ? '.' : ': '.mb_strimwidth($message, 0, 500, '…'));
    }

    /**
     * Lets the sandbox kept for the branch go, unless the next review is likely
     * to want it: after every review, or only after one that asked for changes,
     * which the author pushes a fix for within minutes more often than not.
     */
    protected function coolUnlessWanted(Repository $repository, int $build, string $warm, string $mode): void
    {
        if (! $this->sandbox instanceof CloudflareSandbox || ! $this->sandbox->keptWarm) {
            return;
        }

        $result = ReviewRun::query()->where('repository_id', $repository->getKey())->where('build_number', $build)->value('result');

        if ($mode === CloudflareSandbox::WARM_ALWAYS || strtoupper((string) $result) === 'FAILED') {
            return;
        }

        $this->sandbox->cool($warm);
        $this->forgetWarm(ReviewRun::query()->where('repository_id', $repository->getKey())->where('build_number', $build));
        $this->log->writer((int) $repository->getKey(), $build)?->note('The sandbox was let go: the review asked for no changes.');
    }

    /**
     * Takes the warm sandbox off the runs that showed one, and tells the open
     * pages: one refreshed when the review ended would show it until its time.
     *
     * @param  Builder<ReviewRun>  $runs
     */
    protected function forgetWarm(Builder $runs): void
    {
        foreach ($runs->whereNotNull('warm_until')->get() as $run) {
            $run->update(['warm_until' => null]);
            Broadcasts::runChanged($run);
        }
    }

    /**
     * Gives the branch back, and starts a job of its own for a review that was
     * handed to this one and that it will not get to.
     */
    protected function letGo(Repository $repository, string $branch): void
    {
        $left = $this->runner->nextFollowUp($repository, $branch, carryOn: false);

        if ($left !== null) {
            $this->runner->dispatch($repository, $branch, $left['commit'], $left['build']);
        }
    }

    /**
     * Makes the mount point of the scripts the worker's own, whatever the pull
     * request has there: a link named `.review` would have it made wherever the
     * link points.
     */
    protected function takeReviewFiles(string $directory): void
    {
        foreach (["{$directory}/.review", "{$directory}/.review/skill"] as $path) {
            if (is_link($path)) {
                unlink($path);
            }
        }

        File::ensureDirectoryExists("{$directory}/.review/skill");
    }

    /**
     * The review's own files, kept out of what git calls untracked. The scripts
     * write this list themselves where they can; these are the same names.
     */
    protected function excludeReviewFiles(string $directory): void
    {
        $path = "{$directory}/.git/info/exclude";
        $listed = File::exists($path) ? File::lines($path)->all() : [];
        $missing = array_diff(self::REVIEW_FILES, $listed);

        if ($missing !== []) {
            File::ensureDirectoryExists(dirname($path));
            File::append($path, implode("\n", $missing)."\n");
        }
    }

    protected function homeDirectory(string $directory): string
    {
        return "{$directory}-home";
    }

    /**
     * A run whose job died: nothing is left to do but let go of what it held.
     */
    public function abandon(Repository $repository, string $branch, ?string $commit, int $build, int $rounds = 1, bool $trial = false): void
    {
        File::deleteDirectory($this->workDirectory($repository, $build));
        File::deleteDirectory($this->homeDirectory($this->workDirectory($repository, $build)));

        if ($rounds > 1) {
            // A job that died in a later review left that one behind: its own was
            // finished before it carried on.
            $round = $this->runner->forgetRound($repository, $build);

            if ($round !== null) {
                [$build, $commit] = [$round['build'], $round['commit']];
            }

            // A job that died holding the branch would be handed every next review.
            $this->letGo($repository, $branch);
        }

        $commit ??= ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->value('commit');

        if (is_string($commit)) {
            $this->finish($repository, $branch, $commit, $build, reviewing: ! $trial, trial: $trial);
        }

        // Died before it began: nothing else will ever close its record.
        $this->queued->close($repository, $build, ReviewRun::STATUS_FAILED, 'The review did not start');

        $this->runner->markEnded($repository, $build);
    }

    protected function finish(Repository $repository, string $branch, string $commit, int $build, bool $reviewing, bool $trial = false): void
    {
        $this->conclusion->conclude($repository, $branch, $commit, $build, $reviewing, $trial);
    }

    protected function status(Repository $repository, string $commit, int $build, BuildState $state, string $description): void
    {
        $this->conclusion->show($repository, $commit, $build, $state, $description);
    }

    /**
     * What the sandbox clones for itself, or null when the worker clones: on Docker,
     * for a connection that does not ask for it, for a host the sandbox cannot
     * reach, for a Worker deployed before it could clone, and for a merged pull
     * request whose host does not say what its merge was made on. A public
     * repository on GitHub or Bitbucket needs no credential and is on the
     * internet, so a sandbox always clones it; a git server may be on a network
     * only the worker reaches.
     *
     * @return array{ref: string, name: string, commit: string, base: string|null, destination?: string}|null
     *
     * @throws CheckoutFailed
     */
    protected function sandboxSource(Repository $repository, string $branch, ?string $commit, ?Trial $trial): ?array
    {
        if (! $this->sandbox instanceof CloudflareSandbox
            || ! ($repository->integration->clonesInSandbox() || ($this->checkout->anonymous($repository) && ! $repository->driver() instanceof GitDriver))
            || ! $this->checkout->reachableFromSandbox($repository)
            || ! $this->sandbox->clonesItself()) {
            return null;
        }

        if ($trial?->mergeCommit !== null) {
            $merge = $repository->sourceControl()->resolveCommit($trial->mergeCommit);
            $parents = $merge === null ? [] : ($repository->sourceControl()->getCommit($merge)['parents'] ?? []);

            if ($parents === []) {
                return null;
            }

            // A merge commit has the branch as its second parent. A squash is the
            // change itself, on top of the destination as it was.
            [$head, $base] = count($parents) > 1 ? [$parents[1], $parents[0]] : [$merge, $parents[0]];

            return ['ref' => "refs/heads/{$trial->destination}", 'name' => $trial->destination, 'commit' => $head, 'base' => $base, 'destination' => $trial->destination];
        }

        if ($trial?->pullRef !== null) {
            if (preg_match('#^pull/[0-9]+/head$#', $trial->pullRef) !== 1) {
                throw CheckoutFailed::unsafeName($trial->pullRef);
            }

            $ref = "refs/{$trial->pullRef}";

            return ['ref' => $ref, 'name' => 'pull-head', 'commit' => $this->checkout->remoteCommit($repository, $ref), 'base' => null];
        }

        // A pull ref is a ref of its own, not under heads/.
        $ref = PullRef::id($branch) !== null ? "refs/{$branch}" : "refs/heads/{$branch}";

        return ['ref' => $ref, 'name' => $branch, 'commit' => $commit ?? $this->checkout->remoteCommit($repository, $ref), 'base' => null];
    }

    /**
     * @param  array<string, string>  $environment
     * @param  array{url: string, authorization: string|null, ref: string, name: string, commit: string, destination: string|null, base: string|null, since: string|null}|null  $clone  what a Cloudflare sandbox clones for itself, from Checkout::forSandbox()
     */
    protected function step(string $name, Repository $repository, int $build, string $script, string $directory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?string $composerCache = null, ?array $clone = null): ProcessResult
    {
        $log = $this->log->writer((int) $repository->getKey(), $build, $environment);
        $log?->note("── {$name} ──");

        // Someone may stop the review while its reviewer works.
        $this->sandbox->stopWhen = fn (): bool => $this->stops->requested($repository, $build) !== null;

        try {
            $result = $this->sandbox->run("review-{$repository->getKey()}-{$build}-{$name}", $script, $directory, $environment, $trusted, $timeout, $home, $lockedGit, $log === null ? null : $log->write(...), $composerCache, $clone);
        } catch (ReviewStopped $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $log?->note("The {$name} step was cut short: ".self::reason($exception));

            throw $exception;
        } finally {
            $this->sandbox->stopWhen = null;
        }

        if ($result->failed()) {
            $log?->note("The {$name} step ended with exit code {$result->exitCode()}.");
        } else {
            $log?->flush();
        }

        // Not its output: that may quote the repository's code or a key a tool
        // printed, and this application's log is shared by every tenant. The run's
        // own log has it, with the secrets taken out.
        if ($result->failed()) {
            Log::warning("The {$name} step of a container review failed", [
                'repository' => $repository->fullName(),
                'build' => $build,
                'exit' => $result->exitCode(),
            ]);
        }

        return $result;
    }

    /**
     * What the scripts read about the run, under the names Bitbucket Pipelines
     * gave them.
     *
     * @return array<string, string>
     */
    protected function pipelineEnvironment(Repository $repository, string $branch, string $commit, int $build): array
    {
        return [
            'BITBUCKET_BRANCH' => $branch,
            'BITBUCKET_COMMIT' => $commit,
            'BITBUCKET_BUILD_NUMBER' => (string) $build,
            'BITBUCKET_WORKSPACE' => $repository->workspace,
            'BITBUCKET_REPO_SLUG' => $repository->slug,
            'BITBUCKET_CLONE_DIR' => Sandbox::WORK,
            // Nothing runs with a terminal, and nothing should wait for one.
            'CI' => 'true',
        ];
    }

    /**
     * The commit the last review ended on, from the context the precheck wrote.
     */
    protected function lastReviewedCommit(string $directory): ?string
    {
        $context = rescue(fn (): mixed => File::json("{$directory}/review-context.json"), null, false);
        $commit = is_array($context) ? ($context['last_reviewed_commit'] ?? null) : null;

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    protected function workDirectory(Repository $repository, int $build): string
    {
        return rtrim((string) config('review.container.work_directory'), '/')."/{$repository->getKey()}-{$build}";
    }
}
