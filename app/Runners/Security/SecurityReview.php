<?php

declare(strict_types=1);

namespace App\Runners\Security;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\Checkout;
use App\Runners\Container\RunLog;
use App\Runners\Container\Sandbox;
use App\Runners\Container\Sandboxes;
use App\Runners\ContainerRunner;
use App\Services\ReviewCredentials;
use App\Services\ReviewFiling;
use App\Services\ReviewInstructions;
use App\Services\ReviewStack;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * A security review of a branch as it stands: no pull request and no diff, the
 * whole checkout in scope, and a report on the review's own page as its result.
 *
 * It runs where a pull request's review runs, in a sandbox on a clone of the
 * branch, and is told what to do by one prompt, `security-prompt.md`, with the
 * guide for the repository's stack beside it. Nothing is posted to the
 * repository's host: there is nothing there to post it on.
 */
class SecurityReview
{
    public const KIND = 'security';

    public function __construct(
        protected Checkout $checkout,
        protected Sandbox $sandbox,
        protected Sandboxes $sandboxes,
        protected ReviewCredentials $credentials,
        protected ReviewFiling $filing,
        protected RunLog $log,
        protected ContainerRunner $runner,
    ) {}

    public function run(Repository $repository, string $branch, int $build, ?int $waited = null): void
    {
        $directory = rtrim((string) config('review.container.work_directory'), '/')."/{$repository->getKey()}-{$build}";
        $environment = $this->credentials->environment($repository->tenant);
        $log = $this->log->writer((int) $repository->getKey(), $build, $environment);
        $run = null;

        try {
            File::ensureDirectoryExists(dirname($directory), 0700);
            File::deleteDirectory($directory);

            $log?->note("Security review of {$repository->fullName()}, branch {$branch}: the whole branch, not a pull request. Nothing is posted.");

            // Where this installation runs its reviewers: Docker here, or a Cloudflare sandbox.
            $sandbox = $this->sandboxes->for(null, $repository, $this->sandbox);

            // On record before anything can fail, so a branch that does not exist
            // ends as a review that did not finish and not as nothing at all. Its
            // commit is known once the branch is cloned.
            // The record made when it was queued, where there is one.
            $run = ReviewRun::query()->updateOrCreate(['repository_id' => $repository->getKey(), 'build_number' => $build], [
                'tenant_id' => $repository->tenant_id,
                'workspace' => $repository->workspace, 'slug' => $repository->slug,
                'branch' => $branch, 'commit' => '',
                'status' => ReviewRun::STATUS_RUNNING, 'runner' => $sandbox->name(),
                'kind' => self::KIND, 'custom' => true, 'agent' => 'claude-code', 'provider' => 'anthropic',
                'queue_seconds' => $waited, 'started_at' => now(),
                'context' => ['pull_request' => ['title' => "Security review of {$branch}"]],
            ]);

            // Claude Code is the reviewer, and it reviews with the tenant's own key.
            if (! array_key_exists('ANTHROPIC_API_KEY', $environment) && ! array_key_exists('CLAUDE_CODE_OAUTH_TOKEN', $environment)) {
                $log?->note('This tenant has no Anthropic connection, which a security review needs. Add one under AI providers.');
                $run->finish(ReviewRun::STATUS_FAILED);

                return;
            }

            $commit = $this->checkout->clone($repository, $branch, null, $directory);
            $run->update(['commit' => $commit]);

            $this->prepare($repository, $directory);
            $log?->note('── review ──');

            $result = $sandbox->run(
                "review-{$repository->getKey()}-{$build}-security",
                'sh .review/skill/run-security.sh && REVIEW_AGENT=claude-code sh .review/skill/collect-usage.sh',
                $directory,
                $environment + $this->settings() + ['REVIEW_COMMIT' => $commit, 'CI' => 'true'],
                trusted: false,
                timeout: (int) config('review.security.timeout'),
                output: $log === null ? null : $log->write(...),
            );
            $log?->flush();

            if ($sandbox->seconds !== null) {
                $run->update(['sandbox' => $sandbox->name(), 'sandbox_size' => (string) config('review.cloudflare.instance'), 'sandbox_seconds' => $sandbox->seconds, 'sandbox_cost' => $sandbox->cost]);
            }

            $report = $result->successful() ? $this->json("{$directory}/ai-review.json") : null;

            if ($report === null || ! is_array($report['findings'] ?? null)) {
                $log?->note('The reviewer left no report; the review is recorded as not finished.');
                $run->finish(ReviewRun::STATUS_FAILED);

                return;
            }

            $usage = $this->json("{$directory}/ai-usage.json") ?? [];

            $this->filing->file($repository, [
                'branch' => $branch, 'commit' => $commit, 'build' => $build,
                'result' => $report['result'] ?? null,
                'summary' => $report['summary'] ?? null,
                'findings' => $report['findings'],
                'manual_review' => $report['manual_review'] ?? null,
                'usage' => is_array($usage['usage'] ?? null) ? $usage['usage'] : [],
                'cost' => is_numeric($usage['cost'] ?? null) ? (float) $usage['cost'] : null,
                // Any security finding of weight makes the report one to act on.
                'fail_on' => 'medium',
                'custom' => true,
                // There is no pull request to publish it on.
                'publish' => false,
            ]);

            $log?->note('Filed: '.count($report['findings']).' finding(s). The report is on this page.');
        } catch (Throwable $exception) {
            $log?->note('The security review did not finish: '.class_basename($exception).'.');
            $run?->finish(ReviewRun::STATUS_FAILED);

            throw $exception;
        } finally {
            $log?->flush();
            File::deleteDirectory($directory);
            $this->runner->markEnded($repository, $build);
        }
    }

    /**
     * Closes the run of a job that died, so it does not stay "reviewing" for ever.
     */
    public function abandon(Repository $repository, int $build): void
    {
        ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            // Also one that died before it began.
            ->whereIn('status', [ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_QUEUED])
            ->get()
            ->each(fn (ReviewRun $run) => $run->finish(ReviewRun::STATUS_FAILED));

        $this->runner->markEnded($repository, $build);
    }

    /**
     * What the installation sets for a security review, as its script reads it.
     *
     * @return array<string, string>
     */
    protected function settings(): array
    {
        return array_filter([
            'SECURITY_REVIEW_MAX_BUDGET' => (string) config('review.security.max_budget'),
            'SECURITY_REVIEW_MODEL' => (string) config('review.security.model'),
            'SECURITY_REVIEW_EFFORT' => (string) config('review.security.effort'),
        ], fn (string $value): bool => preg_match('/^[A-Za-z0-9._:\/@\[\]-]+$/', $value) === 1);
    }

    /**
     * The worker's own scripts and the stack's guide, whatever the branch has in
     * their place: nothing the reviewer is told comes from the code it reviews.
     */
    protected function prepare(Repository $repository, string $directory): void
    {
        foreach (["{$directory}/.review", "{$directory}/.review/skill", "{$directory}/.review-instructions", "{$directory}/ai-review.json", "{$directory}/ai-usage.json"] as $path) {
            if (is_link($path)) {
                unlink($path);
            }
        }

        File::delete(["{$directory}/ai-review.json", "{$directory}/ai-usage.json"]);
        File::deleteDirectory("{$directory}/.review-instructions");
        File::ensureDirectoryExists("{$directory}/.review/skill");
        File::ensureDirectoryExists("{$directory}/.review-instructions");

        // What the repository is set to, else what its composer.json names.
        // The platform's own prompt, where it keeps one in place of the one that ships.
        $prompt = ReviewInstructions::base(ReviewInstructions::SECURITY);

        if ($prompt !== null) {
            File::put("{$directory}/.review-instructions/security-prompt.md", $prompt);
        }

        $composer = "{$directory}/composer.json";
        $stacks = ReviewStack::resolve($repository)
            ?: ReviewStack::fromComposer(is_file($composer) && ! is_link($composer) ? (string) File::get($composer) : null);

        foreach ($stacks as $stack) {
            $guide = resource_path("review/skill/references/stack-{$stack}.md");

            if (is_file($guide)) {
                File::copy($guide, "{$directory}/.review-instructions/stack-{$stack}.md");
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function json(string $path): ?array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 5_000_000) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
