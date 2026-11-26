<?php

declare(strict_types=1);

namespace App\Filament;

use App\Enums\ReviewProvider;
use App\Models\ReviewRun;
use App\Runners\Diff\DiffReview;
use App\Runners\Security\SecurityReview;

/**
 * How a run reads on a page: its outcome in words, and the tone that goes with it.
 */
class RunPresenter
{
    /**
     * @return array{label: string, tone: string} tone is approved, changes, manual, running, failed or neutral
     */
    public static function outcome(ReviewRun $run): array
    {
        return match (true) {
            $run->status === ReviewRun::STATUS_QUEUED => ['label' => __('Queued'), 'tone' => 'running'],
            $run->status === ReviewRun::STATUS_RUNNING => ['label' => __('Reviewing…'), 'tone' => 'running'],
            $run->status === ReviewRun::STATUS_PENDING => ['label' => __('Waiting its turn'), 'tone' => 'running'],
            $run->status === ReviewRun::STATUS_RESTARTED => ['label' => __('Started again'), 'tone' => 'neutral'],
            // A branch's security review has nowhere to be posted: its report is its outcome.
            $run->status === ReviewRun::STATUS_UNPUBLISHED && $run->kind === SecurityReview::KIND => strtoupper((string) $run->result) === 'FAILED'
                ? ['label' => __('Findings to act on'), 'tone' => 'changes']
                : ['label' => __('Nothing to act on'), 'tone' => 'approved'],
            $run->status === ReviewRun::STATUS_UNPUBLISHED => ['label' => __('Trial, not posted'), 'tone' => 'neutral'],
            $run->status === ReviewRun::STATUS_TIMEOUT => ['label' => __('Timed out'), 'tone' => 'failed'],
            $run->status === ReviewRun::STATUS_STOPPED => ['label' => __('Stopped'), 'tone' => 'neutral'],
            $run->status === ReviewRun::STATUS_FAILED => ['label' => __('Did not finish'), 'tone' => 'failed'],
            $run->status === ReviewRun::STATUS_FINISHED && strtoupper((string) $run->result) === 'FAILED' => ['label' => __('Changes requested'), 'tone' => 'changes'],
            $run->status === ReviewRun::STATUS_FINISHED && $run->needsManualReview() => ['label' => __('Manual review'), 'tone' => 'manual'],
            $run->status === ReviewRun::STATUS_FINISHED => ['label' => __('Approved'), 'tone' => 'approved'],
            default => ['label' => ucfirst((string) $run->status), 'tone' => 'neutral'],
        };
    }

    /**
     * One sentence on where a run stands, for a run that has no summary to show.
     */
    public static function standing(ReviewRun $run): string
    {
        return match ($run->status) {
            ReviewRun::STATUS_QUEUED => __('The review is queued: it waits for a worker, or for the review of the commit before it to finish.'),
            ReviewRun::STATUS_RUNNING => __('The review is still running.'),
            ReviewRun::STATUS_PENDING => __('Another review of this pull request was running, so this one waits and is started again when that one is done.'),
            ReviewRun::STATUS_RESTARTED => $run->restarted_as === null
                ? __('This review waited for another one and was then started again.')
                : __('This review waited for another one and was then started again as run #:run.', ['run' => $run->restarted_as]),
            ReviewRun::STATUS_SKIPPED => $run->superseded_by === null
                ? __('Not reviewed: there was nothing new to review.')
                : __('Not reviewed: a newer commit was reviewed instead.'),
            ReviewRun::STATUS_FAILED => __('The review did not finish. Its log, where there is one, says where it stopped; "Re-run" starts a new one.'),
            ReviewRun::STATUS_TIMEOUT => __('The review never reported back and was stopped. "Re-run" starts a new one.'),
            ReviewRun::STATUS_STOPPED => __('Stopped before it finished. "Re-run" starts a new one.'),
            default => __('No summary.'),
        };
    }

    /**
     * The findings still open on a run, counted by severity, worst first.
     *
     * @return array<string, int> severity in lower case to count, without the empty ones
     */
    public static function openBySeverity(ReviewRun $run): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($run->findings ?? [] as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? ''));

            if (in_array($finding['status'] ?? null, ['new', 'open'], true) && isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return array_filter($counts);
    }

    /**
     * Who reviewed, by the name people know it under.
     */
    public static function reviewer(ReviewRun $run): ?string
    {
        if ($run->agent === null) {
            return null;
        }

        return match (true) {
            $run->agent === DiffReview::AGENT => __('The diff alone'),
            default => ReviewProvider::tryFrom($run->agent)?->text() ?? $run->agent,
        };
    }

    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        // Hours too: a run the sweep closed took over an hour, and mm:ss wraps there.
        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Whether the run is still going: queued, waiting its turn or reviewing.
     */
    public static function live(ReviewRun $run): bool
    {
        return in_array($run->status, [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_PENDING], true);
    }

    public static function title(ReviewRun $run): string
    {
        $title = $run->context['pull_request']['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $run->branch;
    }
}
