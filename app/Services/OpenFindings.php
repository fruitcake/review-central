<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Enums\FindingVerdictKind;
use App\Llm\Agents\FindingChecker;
use App\Models\FindingVerdict;
use App\Models\OpenFinding;
use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The backlog of findings a pull request was merged with: kept when it is merged,
 * and checked now and then against the main branch by a small model.
 */
class OpenFindings
{
    /** How often a team may have its open findings checked, by setting, in days. */
    public const INTERVALS = ['weekly' => 7, 'monthly' => 30];

    /** Characters of a file the checking model reads. */
    public const FILE_LENGTH = 60000;

    /**
     * Keeps what the pull request's last review still had open: new or open, and
     * not called no problem or wrong by anyone in the team. Trials are no review of
     * the pull request and count for nothing here.
     *
     * @return int how many were kept
     */
    public function record(Repository $repository, int $pullRequestId, ?string $mergeCommit): int
    {
        $run = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('pull_request_id', $pullRequestId)
            ->where('custom', false)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->latest('started_at')
            ->first();

        if ($run === null) {
            return 0;
        }

        $dismissed = FindingVerdict::query()
            ->where('review_run_id', $run->getKey())
            ->whereIn('verdict', [FindingVerdictKind::NotAProblem, FindingVerdictKind::Wrong])
            ->pluck('finding_id')
            ->all();

        $kept = 0;

        foreach (array_filter($run->findings ?? [], is_array(...)) as $finding) {
            $id = (string) ($finding['id'] ?? '');

            if ($id === '' || ! in_array(strtolower((string) ($finding['status'] ?? 'new')), ['new', 'open'], true) || in_array($id, $dismissed, true)) {
                continue;
            }

            OpenFinding::query()->createOrFirst(
                ['repository_id' => $repository->getKey(), 'pull_request_id' => $pullRequestId, 'finding_id' => $id],
                [
                    'tenant_id' => $repository->tenant_id,
                    'review_run_id' => $run->getKey(),
                    'severity' => is_string($finding['severity'] ?? null) ? strtoupper($finding['severity']) : null,
                    'title' => Str::limit((string) ($finding['title'] ?? $id), 250),
                    'path' => filled($finding['path'] ?? null) ? (string) $finding['path'] : null,
                    'line' => is_numeric($finding['line'] ?? null) ? (int) $finding['line'] : null,
                    'details' => filled($finding['details'] ?? null) ? Str::limit((string) $finding['details'], 5000) : null,
                    'merge_commit' => $mergeCommit,
                ],
            );
            $kept++;
        }

        return $kept;
    }

    /**
     * How often the repository's team has its open findings checked, in days; null
     * when it does not.
     */
    public static function interval(Repository $repository): ?int
    {
        return self::INTERVALS[ReviewSettings::defaults($repository->tenant)->findings_check ?? ''] ?? null;
    }

    /**
     * The repository's open findings that are due a check: never checked, or not
     * within the team's interval.
     *
     * @return Collection<int, OpenFinding>
     */
    public static function due(Repository $repository): Collection
    {
        $days = self::interval($repository);

        if ($days === null) {
            return new Collection;
        }

        return OpenFinding::query()
            ->where('repository_id', $repository->getKey())
            ->active()
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subDays($days)))
            ->get();
    }

    /**
     * Checks findings against their file as it is on the main branch, one request
     * per file. A file that is gone resolves its findings; one without a file stays
     * as it is, there being nothing to read.
     *
     * @param  Collection<int, OpenFinding>  $findings  of the one repository
     * @return int how many were checked
     */
    public function check(Repository $repository, Collection $findings): int
    {
        $connection = LlmConnections::small($repository->tenant);
        $driver = $connection?->driver();

        if ($connection === null || ! $driver instanceof LlmDriver) {
            return 0;
        }

        $source = $repository->sourceControl();
        $checked = 0;

        foreach ($findings->filter(fn (OpenFinding $finding): bool => $finding->path !== null)->groupBy('path') as $path => $group) {
            $file = $source->getFile((string) $path);

            if ($file === null) {
                foreach ($group as $finding) {
                    $this->settle($finding, OpenFinding::RESOLVED, 'The file is no longer on the main branch.', null);
                }
                $checked += $group->count();

                continue;
            }

            $response = $driver->checks($connection, self::material((string) $path, $file, $group));
            $verdicts = FindingChecker::read($response, $group->pluck('finding_id')->all());

            foreach ($group as $finding) {
                $verdict = $verdicts[$finding->finding_id] ?? null;

                if ($verdict !== null) {
                    $this->settle($finding, $verdict['verdict'], $verdict['note'], $response->meta->model);
                    $checked++;
                }
            }
        }

        return $checked;
    }

    protected function settle(OpenFinding $finding, string $verdict, string $note, ?string $model): void
    {
        $finding->update([
            'status' => $verdict,
            'note' => $note,
            'checked_at' => now(),
            'checked_by_model' => $model,
            'closed_at' => $verdict === OpenFinding::RESOLVED ? now() : null,
            'closed_by' => $verdict === OpenFinding::RESOLVED ? 'check' : null,
        ]);
    }

    /**
     * @param  Collection<int, OpenFinding>  $findings
     */
    protected static function material(string $path, string $file, Collection $findings): string
    {
        $items = $findings->map(fn (OpenFinding $finding): string => "## {$finding->finding_id} · {$finding->severity} · {$finding->title}\n"
            .($finding->line === null ? '' : "Raised on line {$finding->line}.\n")
            .Str::limit((string) $finding->details, 2000))->implode("\n\n");

        return "# {$path}, as it is now\n\n".Str::limit($file, self::FILE_LENGTH)."\n\n# The findings\n\n{$items}";
    }
}
