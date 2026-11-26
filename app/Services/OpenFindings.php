<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Enums\FindingVerdictKind;
use App\Jobs\CheckOpenFindings;
use App\Llm\Agents\FindingChecker;
use App\Models\FindingVerdict;
use App\Models\OpenFinding;
use App\Models\Repository;
use App\Models\ReviewRun;
use Illuminate\Database\Eloquent\Builder;
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

    /** Characters of a file, with its line numbers, the checking model reads whole; a longer one is read around the findings' lines, up to as much. */
    public const FILE_LENGTH = 60000;

    /** Findings asked about in one request. */
    public const PER_REQUEST = 10;

    /** Lines either side of a finding's line that a long file's excerpt shows. */
    public const EXCERPT_LINES = 80;

    /**
     * The backlog as data, newest first: what is still open on the repositories
     * given, or every finding there whatever its status.
     *
     * @param  Builder<Repository>  $repositories
     * @return array<int, array<string, mixed>>
     */
    public function listed(Builder $repositories, bool $closedToo = false, ?string $path = null, int $limit = 50): array
    {
        return OpenFinding::query()
            ->with('repository')
            ->whereIn('repository_id', $repositories->select('id'))
            ->when(! $closedToo, fn (Builder $query) => $query->active())
            // A prefix taken literally: in LIKE an underscore in a path would match any character.
            ->when(filled($path), fn (Builder $query) => $query->whereRaw('substr(path, 1, ?) = ?', [mb_strlen((string) $path), $path]))
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (OpenFinding $finding): array => array_filter([
                'id' => $finding->getKey(),
                'repository' => $finding->repository->fullName(),
                'pull_request_id' => $finding->pull_request_id,
                'finding_id' => $finding->finding_id,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'path' => $finding->path,
                'line' => $finding->line,
                'details' => $finding->details,
                'status' => $finding->status,
                'status_text' => OpenFinding::statusLabels()[$finding->status] ?? $finding->status,
                'note' => $finding->note,
                'merged_at' => $finding->created_at?->toIso8601String(),
                'checked_at' => $finding->checked_at?->toIso8601String(),
            ], fn (mixed $value): bool => $value !== null))
            ->all();
    }

    /**
     * Closes findings that are still active, by someone who says why: fixed
     * elsewhere, accepted, not a problem. A check never opens them again.
     *
     * @param  Collection<int, OpenFinding>  $findings
     * @return int how many were closed
     */
    public function close(Collection $findings, ?string $reason, ?string $by): int
    {
        $closed = 0;

        foreach ($findings->filter(fn (OpenFinding $finding): bool => in_array($finding->status, OpenFinding::ACTIVE, true)) as $finding) {
            $closed += (int) $finding->update(['status' => OpenFinding::DISMISSED, 'note' => filled($reason) ? $reason : null, 'closed_at' => now(), 'closed_by' => $by]);
        }

        return $closed;
    }

    /**
     * Queues a check against the main branch of the findings that can be checked:
     * active, and in a file. One job per repository.
     *
     * @param  Collection<int, OpenFinding>  $findings
     * @return int how many were queued
     */
    public function queueCheck(Collection $findings): int
    {
        $checkable = $findings->filter(fn (OpenFinding $finding): bool => in_array($finding->status, OpenFinding::ACTIVE, true) && $finding->path !== null);

        foreach ($checkable->groupBy('repository_id') as $repositoryId => $group) {
            CheckOpenFindings::dispatch((int) $repositoryId, $group->map(fn (OpenFinding $finding): int => (int) $finding->getKey())->values()->all());
        }

        return $checkable->count();
    }

    /**
     * Keeps what the pull request's last review still had open: new or open, and
     * not called no problem or wrong by anyone in the team. Trials are no review of
     * the pull request and count for nothing here.
     *
     * @return int how many were kept
     */
    public function record(Repository $repository, int $pullRequestId, ?string $mergeCommit, ?string $destination = null): int
    {
        // Kept once, at the first word of the merge: a later delivery, after a
        // review that ran since, adds nothing.
        if (OpenFinding::query()->where('repository_id', $repository->getKey())->where('pull_request_id', $pullRequestId)->exists()) {
            return 0;
        }

        // Only what reached the main branch, which is what they are checked against:
        // merged into a release branch, the code is not there yet.
        $main = $destination === null ? null : rescue(fn (): mixed => $repository->sourceControl()->getRepository()['mainbranch']['name'] ?? null, null, false);

        if (is_string($main) && $main !== '' && $main !== $destination) {
            return 0;
        }

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

                // A few findings a request, so every one gets an answer within the model's room.
                foreach ($group->chunk(self::PER_REQUEST) as $chunk) {
                    [$text, $covered] = self::material((string) $path, $file, $chunk);
                    $response = $driver->checks($connection, $text);
                    // By the row's own id: two pull requests can each have an F1 in this file.
                    $verdicts = FindingChecker::read($response, $chunk->map(fn (OpenFinding $finding): string => (string) $finding->getKey())->all());

                    foreach ($chunk as $finding) {
                        $verdict = $verdicts[(string) $finding->getKey()] ?? null;

                        if ($verdict === null) {
                            continue;
                        }

                        // Code the model was not shown whole is not code that is gone.
                        $status = $verdict['verdict'] === OpenFinding::RESOLVED && ! in_array($finding->getKey(), $covered, true) ? OpenFinding::UNCLEAR : $verdict['verdict'];
                        $checked += (int) $this->settle($finding, $status, $verdict['note'], $response->meta->model);
                    }
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
     * What the checking model reads: the file with its line numbers, whole when that
     * fits, or else the lines around each finding's line, merged where they overlap,
     * for as many findings as fit. Nothing is cut off unsaid.
     *
     * @param  Collection<int, OpenFinding>  $findings
     * @return array{0: string, 1: array<int, int>} the text, and the ids of the findings it can call resolved: those of a file shown whole
     */
    protected static function material(string $path, string $file, Collection $findings): array
    {
        // Only real line ends: \R without the u flag also splits on a byte inside a UTF-8 character.
        $lines = preg_split('/\r\n|\n|\r/', $file) ?: [];
        $count = count($lines);
        $numbered = fn (int $from, int $to): string => implode("\n", array_map(fn (int $index): string => ($index + 1).': '.$lines[$index], range($from, $to)));
        $whole = $count === 0 ? '' : $numbered(0, $count - 1);

        if (strlen($whole) <= self::FILE_LENGTH) {
            $shown = $whole;
            $heading = "# {$path}, as it is now";
            // All of it, but for a finding whose line is past the end: the file moved
            // too much for it to be found by its line.
            $covered = $findings->filter(fn (OpenFinding $finding): bool => $finding->line === null || $finding->line <= $count)
                ->map(fn (OpenFinding $finding): int => (int) $finding->getKey())
                ->values()
                ->all();
        } else {
            // Windows around each finding's line, nearest the top first, merged where
            // they touch, until the budget is spent. An excerpt can show a problem is
            // still there, never that it is gone: the code may have moved out of it.
            $windows = [];
            $length = 0;

            foreach ($findings->filter(fn (OpenFinding $finding): bool => $finding->line !== null && $finding->line <= $count)->sortBy('line') as $finding) {
                $from = max(0, (int) $finding->line - 1 - self::EXCERPT_LINES);
                $to = min($count - 1, (int) $finding->line - 1 + self::EXCERPT_LINES);
                $last = array_key_last($windows);

                if ($last !== null && $from <= $windows[$last][1] + 1) {
                    $grown = [$windows[$last][0], max($windows[$last][1], $to)];
                    $extra = strlen($numbered($grown[0], $grown[1])) - strlen($numbered($windows[$last][0], $windows[$last][1]));

                    if ($length + $extra > self::FILE_LENGTH) {
                        continue;
                    }

                    $windows[$last] = $grown;
                    $length += $extra;
                } else {
                    $size = strlen($numbered($from, $to));

                    if ($length + $size > self::FILE_LENGTH) {
                        continue;
                    }

                    $windows[] = [$from, $to];
                    $length += $size;
                }
            }

            $covered = [];

            $shown = implode("\n…\n", array_map(fn (array $window): string => $numbered($window[0], $window[1]), $windows));
            $heading = "# {$path}, as it is now: excerpts around the findings' lines. The rest of the file is not shown, and code not shown is not gone: say present where an excerpt still has the problem, and unclear otherwise.";
        }

        $items = $findings->map(fn (OpenFinding $finding): string => "## {$finding->getKey()} · {$finding->severity} · {$finding->title}\n"
            .($finding->line === null ? '' : "Raised on line {$finding->line}.\n")
            .Str::limit((string) $finding->details, 2000))->implode("\n\n");

        return ["{$heading}\n\n{$shown}\n\n# The findings\n\n{$items}", array_values(array_map('intval', $covered))];
    }
}
