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
        $url = $run->repository->sourceControl()->pullRequestUrl((int) $run->pull_request_id);

        $started = array_map(function (array $row) use ($run, $url, $ignoreExisting): array {
            try {
                $row = ['ignore_existing' => $ignoreExisting] + $row;

                return $row + ['build' => $this->trials->begin($run->tenant, $url, $row)['build']];
            } catch (ReviewTrialRefused $exception) {
                return $row + ['error' => $exception->getMessage()];
            }
        }, array_slice(array_values($rows), 0, ReviewComparison::MAX_ROWS));

        return ReviewComparison::query()->create([
            'tenant_id' => $run->tenant_id,
            'repository_id' => $run->repository_id,
            'pull_request_id' => $run->pull_request_id,
            'review_run_id' => $run->getKey(),
            'user_id' => $user?->getKey(),
            'rows' => $started,
        ]);
    }

    /**
     * Judges the findings of every column together with the model asked for, and
     * keeps what it made of them; a failure is kept as the reason.
     */
    public function analyze(ReviewComparison $comparison, Connection $connection, ?string $model): void
    {
        $driver = $connection->driver();

        if (! $driver instanceof LlmDriver) {
            $comparison->update(['analysis_status' => ReviewComparison::ANALYSIS_FAILED, 'analysis_error' => 'That connection is no AI provider.']);

            return;
        }

        try {
            [$text, $ids] = $this->material($comparison);
            $response = $driver->compares($connection, $text, $model);
            $analysis = Comparer::read($response, $ids);

            if ($analysis === null) {
                throw new RuntimeException('The model gave no comparison.');
            }

            $used = $response->meta->model;

            $comparison->update([
                'analysis_status' => ReviewComparison::ANALYSIS_FINISHED,
                'analysis' => $analysis,
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
     * @return array{0: string, 1: array<int, string>}
     */
    protected function material(ReviewComparison $comparison): array
    {
        $source = $comparison->repository->sourceControl();
        $pullRequest = $source->getPullRequest((string) $comparison->pull_request_id) ?? [];
        $ids = [];
        $reviews = [];

        foreach ($comparison->columns() as $label => $column) {
            if ($column['run'] === null) {
                continue;
            }

            $lines = ["## Review {$label}: ".self::setup($column['run'])];

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

        return [$text, $ids];
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

        foreach ($comparison->columns() as $label => $column) {
            if ($column['run'] === null) {
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
