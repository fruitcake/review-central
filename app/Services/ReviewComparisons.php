<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Exceptions\ReviewTrialRefused;
use App\Llm\Agents\Comparer;
use App\Models\Connection;
use App\Models\ModelPrice;
use App\Models\ReviewComparison;
use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Starts a comparison of setups on one pull request, and has a model judge the
 * findings of its reviews together.
 */
class ReviewComparisons
{
    /** Characters of the pull request's diff the judging model reads. */
    public const DIFF_LENGTH = 150000;

    public function __construct(protected ReviewTrial $trials) {}

    /**
     * A trial for each setup, on the pull request the run reviewed. A setup that
     * cannot be tried keeps why, and the others still start.
     *
     * @param  array<int, array{runner: string|null, provider: string|null, model: string|null, effort: string|null, verify: bool, ignore_existing?: bool}>  $rows
     */
    public function start(ReviewRun $run, array $rows, bool $ignoreExisting, ?User $user): ReviewComparison
    {
        return ReviewComparison::query()->create([
            'tenant_id' => $run->tenant_id,
            'repository_id' => $run->repository_id,
            'pull_request_id' => $run->pull_request_id,
            'review_run_id' => $run->getKey(),
            'user_id' => $user?->getKey(),
            'rows' => $this->trials($run, array_slice(array_values($rows), 0, ReviewComparison::MAX_ROWS), $ignoreExisting),
        ]);
    }

    /**
     * More setups for a comparison, up to its most, each a trial of the pull request
     * as it is now. An analysis made before keeps to the reviews it read.
     *
     * @param  array<int, array{runner: string|null, provider: string|null, model: string|null, effort: string|null, verify: bool, ignore_existing?: bool}>  $rows
     * @return int how many were added
     */
    public function add(ReviewComparison $comparison, array $rows, bool $ignoreExisting): int
    {
        // First the places, under a lock on the comparison: two additions at once
        // each see the other's, and the most holds.
        $reserved = DB::transaction(function () use ($comparison, $rows): array {
            $locked = ReviewComparison::query()->whereKey($comparison->getKey())->lockForUpdate()->firstOrFail();
            $room = ReviewComparison::MAX_ROWS - count($locked->rows);

            if ($room <= 0 || $locked->reviewRun === null) {
                return [];
            }

            $analysis = $locked->analysis;

            // An analysis from before labels were kept read every review there was.
            if ($analysis !== null && ! isset($analysis['labels'])) {
                $analysis['labels'] = array_map('strval', $locked->columns()->filter(fn (array $column): bool => $column['run'] !== null)->keys()->all());
            }

            $existing = array_values($locked->rows);
            $new = array_slice(array_values($rows), 0, $room);
            $locked->update(['rows' => [...$existing, ...$new], 'analysis' => $analysis]);

            return array_combine(range(count($existing), count($existing) + count($new) - 1), $new);
        });

        $run = $comparison->reviewRun;

        if ($reserved === [] || $run === null) {
            $comparison->refresh();

            return 0;
        }

        // Then the trials, once the places are saved: a trial's job is on the queue
        // the moment it starts, and its worker finds its row.
        foreach ($reserved as $index => $row) {
            $started = $this->trials($run, [$row], $ignoreExisting)[0];

            DB::transaction(function () use ($comparison, $index, $started): void {
                $locked = ReviewComparison::query()->whereKey($comparison->getKey())->lockForUpdate()->firstOrFail();
                $saved = array_values($locked->rows);
                $saved[$index] = $started;
                $locked->update(['rows' => $saved]);
            });
        }

        $comparison->refresh();

        return count($reserved);
    }

    /**
     * The reviews an analysis started now reads: the ones not left out that have
     * ended. Taken when it is asked for, so a setup added while it waits is not
     * read by it, nor scored by it.
     *
     * @return array<int, string>
     */
    public static function analysable(ReviewComparison $comparison): array
    {
        return array_map('strval', $comparison->active()
            ->filter(fn (array $column): bool => $column['run'] !== null && ! in_array($column['run']->status, [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_PENDING], true))
            ->keys()
            ->all());
    }

    /**
     * Starts a trial for each setup on the pull request the run reviewed. A setup
     * that cannot be tried keeps why, and the others still start.
     *
     * @param  array<int, array{runner: string|null, provider: string|null, model: string|null, effort: string|null, verify: bool, ignore_existing?: bool}>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function trials(ReviewRun $run, array $rows, bool $ignoreExisting): array
    {
        $url = $run->repository->sourceControl()->pullRequestUrl((int) $run->pull_request_id);

        return array_map(function (array $row) use ($run, $url, $ignoreExisting): array {
            try {
                $row = ['ignore_existing' => $ignoreExisting] + $row;

                $started = $this->trials->begin($run->tenant, $url, $row);

                // The repository the trial runs under, which with two connections for
                // one workspace need not be the review's own.
                return $row + ['build' => $started['build'], 'repository_id' => (int) $started['repository']->getKey()];
            } catch (ReviewTrialRefused $exception) {
                return $row + ['error' => $exception->getMessage()];
            }
        }, $rows);
    }

    /**
     * Judges the findings of every column together with the model asked for, and
     * keeps what it made of them; a failure is kept as the reason.
     */
    /**
     * @param  array<int, string>|null  $labels  the reviews to read, as taken when it was asked for; null for those that can be now
     */
    public function analyze(ReviewComparison $comparison, Connection $connection, ?string $model, ?array $labels = null): void
    {
        $driver = $connection->driver();

        if (! $driver instanceof LlmDriver) {
            $comparison->update(['analysis_status' => ReviewComparison::ANALYSIS_FAILED, 'analysis_error' => 'That connection is no AI provider.']);

            return;
        }

        try {
            [$text, $ids, $labels] = $this->material($comparison, $labels ?? self::analysable($comparison));
            $response = $driver->compares($connection, $text, $model);
            $analysis = Comparer::read($response, $ids);

            if ($analysis === null) {
                throw new RuntimeException('The model gave no comparison.');
            }

            $used = $response->meta->model;

            $comparison->update([
                'analysis_status' => ReviewComparison::ANALYSIS_FINISHED,
                // The reviews it read: one added after has no scores from it.
                'analysis' => $analysis + ['labels' => $labels],
                'analysis_model' => $used,
                'analysis_error' => null,
                'analysis_cost' => $used === null ? null : ModelPrice::estimate([$used => ['input' => $response->usage->inputTokens, 'output' => $response->usage->outputTokens]]),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $comparison->update(['analysis_status' => ReviewComparison::ANALYSIS_FAILED, 'analysis_error' => Str::limit($exception->getMessage(), 1000)]);
        }
    }

    /**
     * What the judging model reads, and the ids of the findings in it.
     *
     * @param  array<int, string>  $labels  the reviews to read
     * @return array{0: string, 1: array<int, string>, 2: array<int, string>} the text, the ids of the findings in it, and the reviews' labels
     */
    protected function material(ReviewComparison $comparison, array $labels): array
    {
        $source = $comparison->repository->sourceControl();
        $pullRequest = $source->getPullRequest((string) $comparison->pull_request_id) ?? [];
        // Only the reviews asked for that are still not left out.
        $columns = $comparison->active()->filter(fn (array $column, string $label): bool => $column['run'] !== null && in_array($label, $labels, true));

        if ($columns->isEmpty()) {
            throw new RuntimeException('None of the reviews it was to read is still in the comparison.');
        }

        // The diff read is the open pull request's as it is now. A review of another
        // commit is judged against code it did not read, so it is said with it; when
        // no review is of the head, the diff is none of theirs.
        $head = ($pullRequest['state'] ?? null) === 'OPEN' ? (string) ($pullRequest['source']['commit']['hash'] ?? '') : '';
        $older = $head === '' ? [] : $columns
            ->filter(fn (array $column): bool => ! ReviewContext::isSameCommit($column['run']->commit, $head))
            ->keys()
            ->all();

        if ($older !== [] && count($older) === $columns->count()) {
            throw new RuntimeException('The pull request has moved on to '.substr($head, 0, 12).' since these reviews, so its diff is not what they read. Start a new comparison from its newest review.');
        }

        $ids = [];
        $reviews = [];

        foreach ($columns as $label => $column) {
            $lines = ["## Review {$label}: ".self::setup($column['run'])
                .(in_array($label, $older, true) ? '
Of an older commit, '.substr($column['run']->commit, 0, 12).': a finding about code this diff no longer has is unclear, not false.' : '')];

            foreach (array_values(array_filter($column['run']->findings ?? [], is_array(...))) as $index => $finding) {
                $id = "{$label}-".($finding['id'] ?? 'F'.($index + 1));
                $ids[] = $id;
                $lines[] = "### {$id} · ".strtoupper((string) ($finding['severity'] ?? '')).' · '.($finding['title'] ?? '')
                    .(filled($finding['path'] ?? null) ? "\n{$finding['path']}".(filled($finding['line'] ?? null) ? ":{$finding['line']}" : '') : '')
                    ."\n".Str::limit((string) ($finding['details'] ?? ''), 3000);
            }

            if (count($lines) === 1) {
                $lines[] = 'No findings.';
            }

            $reviews[] = implode("\n\n", $lines);
        }

        $text = implode("\n\n", [
            '# Pull request: '.($pullRequest['title'] ?? ''),
            Str::limit(ReviewContext::withoutReviewBlock($pullRequest['description'] ?? null), 3000),
            "# The diff\n".Str::limit($source->getPullRequestDiff((string) $comparison->pull_request_id), self::DIFF_LENGTH),
            "# The reviews\n".implode("\n\n", $reviews),
        ]);

        return [$text, $ids, array_map('strval', $columns->keys()->all())];
    }

    /**
     * How a column reviewed, in words: "claude-code · claude-opus-5-5 · high".
     */
    public static function setup(ReviewRun $run): string
    {
        return implode(' · ', array_filter([$run->agent, $run->model, $run->effort === null ? null : "effort {$run->effort}"])) ?: 'the repository\'s setup';
    }

    /**
     * For each column, what the judgement says of it: the issues that hold that it
     * found, those it raised that do not, those it raised that cannot be settled,
     * and the issues that hold that it missed.
     *
     * @return array<string, array{valid: int, false_positive: int, unclear: int, missed: int}>
     */
    public static function scores(ReviewComparison $comparison): array
    {
        $scores = [];
        $issues = $comparison->analysis['findings'] ?? [];

        $read = $comparison->analysis['labels'] ?? null;

        foreach ($comparison->columns() as $label => $column) {
            // As the analysis made them: for the reviews it read, whether left out since or not.
            if ($column['run'] === null || ($read !== null && ! in_array($label, $read, true))) {
                continue;
            }

            $valid = $false = $unclear = $missed = 0;

            foreach ($issues as $issue) {
                $found = array_filter($issue['found_by'], fn (string $id): bool => str_starts_with($id, "{$label}-")) !== [];

                match (true) {
                    $found && $issue['verdict'] === 'valid' => $valid++,
                    $found && $issue['verdict'] === 'false_positive' => $false++,
                    $found => $unclear++,
                    $issue['verdict'] === 'valid' => $missed++,
                    default => null,
                };
            }

            $scores[$label] = ['valid' => $valid, 'false_positive' => $false, 'unclear' => $unclear, 'missed' => $missed];
        }

        return $scores;
    }
}
