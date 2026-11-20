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
use Throwable;

/**
 * The backlog of findings a pull request was merged with: kept when it is merged,
 * and checked now and then against the main branch by a small model.
 */
class OpenFindings
{
    /** How often a team may have its open findings checked, by setting, in days. */
    public const INTERVALS = ['weekly' => 7, 'monthly' => 30];

    /** Characters of a file the checking model reads whole; a longer one is read around the findings' lines. */
    public const FILE_LENGTH = 60000;

    /** Lines either side of a finding's line that a long file's excerpt shows. */
    public const EXCERPT_LINES = 80;

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

        // Called no problem or wrong on any review of the pull request: a later
        // review that carried the finding on keeps its id.
        $dismissed = FindingVerdict::query()
            ->whereIn('review_run_id', ReviewRun::query()
                ->select('id')
                ->where('repository_id', $repository->getKey())
                ->where('pull_request_id', $pullRequestId)
                ->where('custom', false))
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
            // One without a file has nothing to be checked against.
            ->whereNotNull('path')
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
            // One file that cannot be read or answered for leaves the others to be checked.
            try {
                $file = $source->fileOnMainBranch((string) $path);

                if ($file === null) {
                    continue;
                }

                if ($file === false) {
                    foreach ($group as $finding) {
                        $checked += (int) $this->settle($finding, OpenFinding::RESOLVED, 'The file is no longer on the main branch.', null);
                    }

                    continue;
                }

                [$text, $excerpt] = self::material((string) $path, $file, $group);
                $response = $driver->checks($connection, $text);
                // By the row's own id: two pull requests can each have an F1 in this file.
                $verdicts = FindingChecker::read($response, $group->map(fn (OpenFinding $finding): string => (string) $finding->getKey())->all());

                foreach ($group as $finding) {
                    $verdict = $verdicts[(string) $finding->getKey()] ?? null;

                    if ($verdict === null) {
                        continue;
                    }

                    // Code an excerpt leaves out is not code that is gone.
                    $status = $verdict['verdict'] === OpenFinding::RESOLVED && $excerpt && $finding->line === null ? OpenFinding::UNCLEAR : $verdict['verdict'];
                    $checked += (int) $this->settle($finding, $status, $verdict['note'], $response->meta->model);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $checked;
    }

    /**
     * Applies a verdict, unless someone changed the finding while it was checked,
     * such as closing it.
     *
     * @return bool whether it was applied
     */
    protected function settle(OpenFinding $finding, string $verdict, string $note, ?string $model): bool
    {
        return OpenFinding::query()
            ->whereKey($finding->getKey())
            ->whereIn('status', OpenFinding::ACTIVE)
            ->where('updated_at', $finding->updated_at)
            ->update([
                'status' => $verdict,
                'note' => $note,
                'checked_at' => now(),
                'checked_by_model' => $model,
                'closed_at' => $verdict === OpenFinding::RESOLVED ? now() : null,
                'closed_by' => $verdict === OpenFinding::RESOLVED ? 'check' : null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * What the checking model reads: the file with its line numbers, whole, or in
     * excerpts around each finding's line when it is longer than is read, and the
     * findings by their row's id.
     *
     * @param  Collection<int, OpenFinding>  $findings
     * @return array{0: string, 1: bool} the text, and whether the file is shown in excerpts
     */
    protected static function material(string $path, string $file, Collection $findings): array
    {
        $lines = preg_split('/\R/', $file) ?: [];
        $numbered = fn (int $from, int $to): string => implode("\n", array_map(fn (int $index): string => ($index + 1).': '.$lines[$index], range($from, $to)));
        $excerpt = strlen($file) > self::FILE_LENGTH;

        if (! $excerpt || $lines === []) {
            $shown = $lines === [] ? '' : $numbered(0, count($lines) - 1);
        } else {
            $shown = $findings->pluck('line')->filter()->unique()->sort()->map(function (int $line) use ($lines, $numbered): string {
                $from = max(0, $line - 1 - self::EXCERPT_LINES);
                $to = min(count($lines) - 1, $line - 1 + self::EXCERPT_LINES);

                return $from > $to ? '' : $numbered($from, $to);
            })->filter()->implode("\n…\n");
        }

        $items = $findings->map(fn (OpenFinding $finding): string => "## {$finding->getKey()} · {$finding->severity} · {$finding->title}\n"
            .($finding->line === null ? '' : "Raised on line {$finding->line}.\n")
            .Str::limit((string) $finding->details, 2000))->implode("\n\n");

        $heading = $excerpt
            ? "# {$path}, as it is now: excerpts around the findings' lines. The rest of the file is not shown, and code not shown is not gone."
            : "# {$path}, as it is now";

        return ["{$heading}\n\n".Str::limit($shown, self::FILE_LENGTH + 20000)."\n\n# The findings\n\n{$items}", $excerpt];
    }
}
