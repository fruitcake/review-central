<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Models\FindingVerdict;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\RunLog;
use App\Runners\Security\SecurityReview;

/**
 * A review run as an admin looks into it to see why it found what it found: what
 * it was set up with, what it read (the context and the instructions' version),
 * what it cost and took, what it reported and what the team judged, and its log,
 * where the reviewer's every step is. Read-only, for the tenant's admins: the
 * log and the context quote the repository's code and its pull request.
 */
class ReviewRunDetails
{
    /** The most lines of a log one answer gives. */
    public const MAX_LOG_LINES = 5000;

    public function __construct(
        protected RepositoryAccess $access,
        protected RunLog $log,
    ) {}

    /**
     * A run of the repository, by its uuid, or the newest of a pull request or
     * branch, with the other runs of that pull request or branch listed.
     *
     * @param  int  $logLines  the last lines of the log; 0 for none
     * @param  string|null  $match  only the log lines that contain this, case-insensitively
     * @return array<string, mixed>
     *
     * @throws ActionRefused when the user is not an admin of the repository's tenant, or there is no such run
     */
    public function of(User $user, Repository $repository, ?string $run = null, ?int $pullRequest = null, ?string $branch = null, int $logLines = 300, ?string $match = null, bool $context = false): array
    {
        $this->access->mustManage($user, $repository->tenant);

        $runs = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->when($run !== null, fn ($query) => $query->where('uuid', self::uuid($run)))
            ->when($run === null && $pullRequest !== null, fn ($query) => $query->where('pull_request_id', $pullRequest))
            ->when($run === null && $pullRequest === null && $branch !== null, fn ($query) => $query->where('branch', $branch))
            ->latest('started_at')
            ->get();

        $chosen = $runs->first() ?? throw new ActionRefused(match (true) {
            $run !== null => "No review run {$run} of {$repository->fullName()}.",
            $pullRequest !== null => "No review run of pull request {$pullRequest} of {$repository->fullName()}.",
            $branch !== null => "No review run of branch {$branch} of {$repository->fullName()}.",
            default => 'Name a run, a pull request or a branch.',
        });

        // The other runs of the same pull request or branch, to pick one from.
        $siblings = $run === null ? $runs : ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->when($chosen->pull_request_id !== null, fn ($query) => $query->where('pull_request_id', $chosen->pull_request_id), fn ($query) => $query->where('branch', $chosen->branch))
            ->latest('started_at')
            ->get();

        return [
            'repository' => $repository->fullName(),
            'run' => $this->run($chosen, $context),
            'verdicts' => $this->verdicts($chosen),
            'settings' => $this->settings($repository),
            'log' => $this->log($chosen, $logLines, $match),
            'runs' => $siblings->map(fn (ReviewRun $sibling): array => [
                'run' => $sibling->uuid,
                'kind' => self::kind($sibling),
                'commit' => substr($sibling->commit, 0, 12),
                'status' => $sibling->status,
                'result' => $sibling->result,
                'open_findings' => count(array_filter($sibling->findings ?? [], fn (mixed $finding): bool => is_array($finding) && in_array(strtolower((string) ($finding['status'] ?? 'new')), ['new', 'open'], true))),
                'started_at' => $sibling->started_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function run(ReviewRun $run, bool $context): array
    {
        return array_filter([
            'run' => $run->uuid,
            'url' => rescue(fn (): string => ReviewRunResource::getUrl('view', ['record' => $run], panel: 'app', tenant: $run->repository->tenant), null, false),
            'kind' => self::kind($run),
            'pull_request_id' => $run->pull_request_id,
            'branch' => $run->branch,
            'commit' => $run->commit,
            'status' => $run->status,
            'result' => $run->result,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->duration_seconds,
            'queue_seconds' => $run->queue_seconds,
            'runner' => $run->runner,
            'reviewer' => $run->agent,
            'provider' => $run->provider,
            'model' => $run->model,
            'effort' => $run->effort,
            'advisor' => $run->advisor,
            'modules' => $run->modules,
            'instructions_version' => $run->instructions_version,
            'fail_on' => $run->fail_on,
            'tokens' => ['input' => $run->input_tokens, 'output' => $run->output_tokens],
            'usage' => $run->usage,
            'cost' => $run->cost === null ? null : (float) $run->cost,
            'cost_estimated' => $run->cost_estimated,
            'sandbox' => array_filter(['kind' => $run->sandbox, 'size' => $run->sandbox_size, 'seconds' => $run->sandbox_seconds, 'cost' => $run->sandbox_cost], fn (mixed $value): bool => $value !== null),
            'peak_memory_mb' => $run->peak_memory_mb,
            'metrics' => $run->metrics,
            'summary' => $run->summary,
            'manual_review' => $run->manual_review,
            'findings' => $run->findings,
            'restarted_as' => $run->restarted_as,
            'superseded_by' => $run->superseded_by,
            // The context as the run was opened: the pull request, its comments and earlier
            // findings. A trial reviewed as though nobody had got it without those.
            'opening_context' => $context ? $run->context : null,
            'opening_context_note' => $context && $run->context !== null ? 'The context as the run was opened. A trial or an earlier commit reviewed as though nobody had got it without the comments and earlier findings; a review of the pull request got it as it is.' : null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * What the team judged of the run's findings.
     *
     * @return list<array<string, mixed>>
     */
    protected function verdicts(ReviewRun $run): array
    {
        return FindingVerdict::query()->with('user')->where('review_run_id', $run->getKey())->get()
            ->map(fn (FindingVerdict $verdict): array => array_filter([
                'finding' => $verdict->finding_id,
                'verdict' => $verdict->verdict?->value,
                'by' => $verdict->user?->name,
                'reason' => $verdict->reason?->value,
                'note' => $verdict->note,
            ], fn (mixed $value): bool => $value !== null))
            ->values()->all();
    }

    /**
     * The repository's review settings as a review of it would start now: the
     * reviewer's variables (models, effort, parts on or off, never a key), the
     * threshold, the follow-up rounds and whether the ticket is graded.
     *
     * @return array<string, mixed>
     */
    protected function settings(Repository $repository): array
    {
        return [
            'variables' => rescue(fn (): array => ReviewSettings::variables($repository), [], false),
            'fail_on' => ReviewSettings::failOn($repository),
            'max_follow_up_rounds' => ReviewSettings::maxRounds($repository),
            'grades_ticket' => ReviewSettings::gradesTicket($repository),
            'strategy' => rescue(fn (): array => array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, ReviewSettings::strategy($repository)), [], false),
        ];
    }

    /**
     * The last lines of the run's log, or those that contain a text.
     *
     * @return array{lines: list<string>, total: int, truncated: bool}|array{note: string}
     */
    protected function log(ReviewRun $run, int $lines, ?string $match): array
    {
        if ($lines <= 0) {
            return ['note' => 'Left out: ask for log_lines to have it.'];
        }

        $tail = $this->log->tail($run);

        if ($tail === null) {
            return ['note' => 'This run has no log here: it ran elsewhere, or its log was removed after the days logs are kept.'];
        }

        $all = preg_split('/\R/u', rtrim($tail['text'])) ?: [];

        if (filled($match)) {
            $all = array_values(array_filter($all, fn (string $line): bool => stripos($line, (string) $match) !== false));
        }

        $lines = min($lines, self::MAX_LOG_LINES);

        return [
            'lines' => array_slice($all, -$lines),
            'total' => count($all),
            // Only the end of a long log is kept for reading, or more lines matched than were asked for.
            'truncated' => $tail['truncated'] || count($all) > $lines,
        ];
    }

    /** What a run was: the pull request's own review, a trial, or a security review of a branch. */
    protected static function kind(ReviewRun $run): string
    {
        return match (true) {
            $run->kind === SecurityReview::KIND => 'security',
            $run->custom => 'trial',
            default => 'review',
        };
    }

    /** A run's uuid from itself or from the address of its page. */
    protected static function uuid(string $run): string
    {
        return preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $run, $match) === 1 ? strtolower($match[0]) : $run;
    }
}
