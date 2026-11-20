<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\RunPresenter;
use App\Models\ReviewRun;
use App\SourceControl\GitHubClient;
use Illuminate\Support\Facades\Cache;

/**
 * Shows a running review on its pull request as it goes: how long it has taken,
 * and the last lines of its log, in the check a GitHub App keeps on the commit.
 * Hosts without such a check, and a token's commit status, show nothing more.
 *
 * The log is what the review page shows, secrets already taken out. On a public
 * repository whoever reads the pull request would read it too, and the reviewer's
 * words in it are words a pull request can put in its mouth: a filter does not
 * catch a key written in a way it does not know. There only the steps are shown.
 */
class CheckProgress
{
    /** Lines of the log shown: enough to follow, well within GitHub's 65,535 characters. */
    public const LINES = 60;

    /**
     * A line of the runner's own that starts a step: "12:03:01  ── review ──". By
     * name, as the reviewer can write a line of that shape too.
     */
    protected const STEP = '/^\S+\s+── ([a-z]+) ──$/u';

    /** How long whether a repository is public is believed, in seconds. */
    protected const VISIBILITY_SECONDS = 3600;

    public function __construct(protected RunLog $log) {}

    public function show(int $repositoryId, int $build): void
    {
        $run = ReviewRun::query()
            ->where('repository_id', $repositoryId)
            ->where('build_number', $build)
            ->with('repository.integration')
            ->first();

        // Only the pull request's own review: the check is its. A trial on the same
        // commit stays off the pull request, and a security review's findings are
        // not for whoever reads it.
        if ($run === null || $run->status !== ReviewRun::STATUS_RUNNING || $run->custom || $run->kind !== 'pull-request') {
            return;
        }

        $sourceControl = $run->repository->sourceControl();

        if (! $sourceControl instanceof GitHubClient || ! $sourceControl->isApp()) {
            return;
        }

        $public = $this->public($run, $sourceControl);
        $lines = explode("\n", rtrim($this->log->tail($run)['text'] ?? ''));
        $lines = array_slice($public ? array_values(array_filter($lines, fn (string $line): bool => preg_match(self::STEP, $line, $step) === 1 && in_array($step[1], RunLogWriter::PHASES, true))) : $lines, -self::LINES);
        $elapsed = RunPresenter::duration((int) $run->started_at->diffInSeconds(now()));
        $log = ReviewRunResource::getUrl('view', ['record' => $run], panel: 'app', tenant: $run->repository->tenant);

        $sourceControl->showProgress(
            $run->commit,
            "Reviewing · {$elapsed}",
            $public
                ? "The review is running. Its steps so far follow, brought up to date every 20 seconds; [the whole log]({$log}) is in Review Central. The repository is public, so its log is not shown here."
                : "The review is running. The last lines of its log follow, brought up to date every 20 seconds; [the whole log]({$log}) is in Review Central.",
            $lines,
        );
    }

    /**
     * Whether anyone can read the repository, and so its checks. One the host does
     * not say of, or cannot be asked about, counts as public.
     */
    protected function public(ReviewRun $run, GitHubClient $sourceControl): bool
    {
        return (bool) Cache::remember(
            "repository-public:{$run->repository_id}",
            self::VISIBILITY_SECONDS,
            fn (): bool => rescue(fn (): bool => ($sourceControl->getRepository()['private'] ?? null) !== true, true, false),
        );
    }
}
