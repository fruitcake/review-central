<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Enums\ReviewEffort;
use App\Enums\ReviewStrategy;
use App\Llm\Agents\Classifier;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewClassification;
use App\Runners\Container\Trial;
use Illuminate\Support\Str;
use Throwable;

/**
 * Classifies a pull request before its review: how much it asks of the review,
 * what kind of change it is, and whether it touches the database, breaks what
 * others rely on, or matters for security. From its title, its description and
 * the files its diff changes; the only code read is the diff of its migrations,
 * where a mistake costs data.
 *
 * Once per commit: every build of the same commit gets the stored answer, so its
 * effort and model stay the same.
 */
class PullRequestClassifier
{
    /** Files listed one by one; the totals still count every file. */
    public const LISTED_FILES = 300;

    /** Characters of the migrations' diff that are read. */
    public const MIGRATIONS_LENGTH = 18000;

    /**
     * The classification of the commit, made now when there is none. Null when the
     * tenant has no AI provider for it or the answer was no classification; a
     * failing request throws, for the caller to report and go on without.
     */
    public function classify(Repository $repository, string $pullRequestId, string $commit): ?ReviewClassification
    {
        $existing = self::forCommit($repository, $commit);

        if ($existing !== null) {
            return $existing;
        }

        $connection = LlmConnections::small($repository->tenant);
        $driver = $connection?->driver();

        if ($connection === null || ! $driver instanceof LlmDriver) {
            return null;
        }

        $started = hrtime(true);
        $source = $repository->sourceControl();
        $pullRequest = $source->getPullRequest($pullRequestId);

        if ($pullRequest === null) {
            return null;
        }

        $files = self::files($source->getPullRequestDiff($pullRequestId));
        $response = $driver->classifies($connection, self::describe($pullRequest, $files));
        $answer = Classifier::read($response);

        if ($answer === null) {
            return null;
        }

        $model = $response->meta->model;

        // Two builds of one commit can classify at the same time; the first stored wins.
        return ReviewClassification::query()->createOrFirst(
            ['repository_id' => $repository->getKey(), 'commit' => $commit],
            $answer + [
                'tenant_id' => $repository->tenant_id,
                'pull_request_id' => (int) $pullRequestId,
                'files_count' => count($files['files']),
                'lines_added' => $files['added'],
                'lines_removed' => $files['removed'],
                'model' => $model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                // What was read from or written to a cache is priced apart from the rest of the input.
                'cost' => $model === null ? null : ModelPrice::estimate([$model => [
                    'input' => max(0, $response->usage->uncachedInputTokens()),
                    'output' => $response->usage->outputTokens,
                    'cache_read' => $response->usage->cacheReadInputTokens ?? 0,
                    'cache_creation' => $response->usage->cacheWriteInputTokens ?? 0,
                ]]),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ],
        );
    }

    /**
     * Classifies the pull request when the repository asks for it, and says so. In
     * apply mode it gives the effort and model tier the review runs at; recording
     * only, or without a classification, the review runs at what is set. A trial
     * that names its own model or effort tries what it was asked to.
     *
     * @param  string|null  $pullRequestId  null finds the open pull request of the branch
     * @param  callable(string): void  $say
     * @return array{effort: ReviewEffort, tier: string}|null
     */
    public function pick(Repository $repository, ?string $pullRequestId, string $branch, string $commit, ?Trial $trial, callable $say): ?array
    {
        $strategy = ReviewSettings::strategy($repository);

        if ($strategy['mode'] === ReviewStrategy::Off || ($trial !== null && ($trial->model !== null || $trial->effort !== null))) {
            return null;
        }

        try {
            $id = $pullRequestId ?? $repository->sourceControl()->findOpenPullRequestByBranch($branch)['id'] ?? null;
            $classification = $id === null ? null : $this->classify($repository, (string) $id, $commit);
        } catch (Throwable $exception) {
            report($exception);
            $say('Not classified ('.$exception->getMessage().'); the review runs at what is set.');

            return null;
        }

        if ($classification === null) {
            $say('Not classified: no open pull request, or no AI provider gave a classification; the review runs at what is set.');

            return null;
        }

        $pick = ReviewSettings::pick($strategy['effort'], $strategy['tier'], $classification->complexity);
        $words = "effort {$pick['effort']->value} and ".($pick['tier'] === null ? "each reviewer's own model" : "the {$pick['tier']} model");

        $say("Classified as {$classification->text()}".($classification->reason === null ? '' : ": {$classification->reason}").' '
            .($strategy['mode'] === ReviewStrategy::Apply ? "Runs at {$words}." : "Would run at {$words}; recorded only, so the review runs at what is set."));

        return $strategy['mode'] === ReviewStrategy::Apply ? $pick : null;
    }

    public static function forCommit(Repository $repository, string $commit): ?ReviewClassification
    {
        return ReviewClassification::query()->where('repository_id', $repository->getKey())->where('commit', $commit)->first();
    }

    /**
     * The files a unified diff changes, each with its lines added and removed, and
     * the diff of the migrations among them.
     *
     * @return array{files: array<string, array{added: int, removed: int}>, added: int, removed: int, migrations: string}
     */
    public static function files(string $diff): array
    {
        /** @var array<string, int> $added */
        $added = [];
        /** @var array<string, int> $removed */
        $removed = [];
        $migrations = '';
        $current = null;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (preg_match('#^diff --git a/(.+?) b/(.+)$#', $line, $match) === 1) {
                $current = $match[2];
                $added[$current] = 0;
                $removed[$current] = 0;
            } elseif ($current !== null && str_starts_with($line, '+') && ! str_starts_with($line, '+++ ')) {
                $added[$current]++;
            } elseif ($current !== null && str_starts_with($line, '-') && ! str_starts_with($line, '--- ')) {
                $removed[$current]++;
            }

            if ($current !== null && str_starts_with($current, 'database/migrations/') && strlen($migrations) < self::MIGRATIONS_LENGTH) {
                $migrations .= $line."\n";
            }
        }

        $files = [];

        foreach ($added as $path => $lines) {
            $files[(string) $path] = ['added' => $lines, 'removed' => $removed[$path]];
        }

        return [
            'files' => $files,
            'added' => array_sum($added),
            'removed' => array_sum($removed),
            'migrations' => Str::limit($migrations, self::MIGRATIONS_LENGTH),
        ];
    }

    /**
     * The pull request as the classifier reads it.
     *
     * @param  array<string, mixed>  $pullRequest
     * @param  array{files: array<string, array{added: int, removed: int}>, added: int, removed: int, migrations: string}  $files
     */
    public static function describe(array $pullRequest, array $files): string
    {
        $listed = array_slice($files['files'], 0, self::LISTED_FILES, true);
        $unlisted = count($files['files']) - count($listed);
        $description = (string) ($pullRequest['description'] ?? '');

        return implode("\n", array_filter([
            'Title: '.($pullRequest['title'] ?? ''),
            trim($description) === '' ? null : 'Description: '.Str::limit(ReviewContext::withoutReviewBlock($description), 2000),
            sprintf('Changed files: %d, +%d -%d lines', count($files['files']), $files['added'], $files['removed']),
            ...array_map(fn (string $path, array $size): string => "{$path} +{$size['added']} -{$size['removed']}", array_keys($listed), $listed),
            $unlisted > 0 ? "... and {$unlisted} more files" : null,
            trim($files['migrations']) === '' ? null : "\nThe migrations, as a diff:\n".$files['migrations'],
        ]));
    }
}
