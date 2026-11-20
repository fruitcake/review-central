<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\RunPresenter;
use App\Models\ReviewRun;
use App\SourceControl\GitHubClient;

/**
 * Shows a running review on its pull request as it goes: how long it has taken,
 * and the last lines of its log, in the check a GitHub App keeps on the commit.
 * Hosts without such a check, and a token's commit status, show nothing more.
 *
 * The log is what the review page shows, secrets already taken out. On a public
 * repository whoever reads the pull request reads it too, as they read the code.
 */
class CheckProgress
{
    /** Lines of the log shown: enough to follow, well within GitHub's 65,535 characters. */
    public const LINES = 60;

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

        $lines = array_slice(explode("\n", rtrim($this->log->tail($run)['text'] ?? '')), -self::LINES);
        $elapsed = RunPresenter::duration((int) $run->started_at->diffInSeconds(now()));

        $sourceControl->showProgress(
            $run->commit,
            "Reviewing · {$elapsed}",
            'The review is running. The last lines of its log follow, brought up to date every 20 seconds; [the whole log]('
                .ReviewRunResource::getUrl('view', ['record' => $run], panel: 'app', tenant: $run->repository->tenant).') is in Review Central.',
            $lines,
        );
    }
}
