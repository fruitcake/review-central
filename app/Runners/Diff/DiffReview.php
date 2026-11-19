<?php

declare(strict_types=1);

namespace App\Runners\Diff;

use App\Connections\LlmDriver;
use App\Enums\BuildState;
use App\Enums\ConnectionKind;
use App\Enums\ReviewProvider;
use App\Exceptions\ReviewerFailed;
use App\Llm\AnthropicDriver;
use App\Llm\OpenAiDriver;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\RunLog;
use App\Runners\Container\Trial;
use App\Runners\DiffRunner;
use App\Runners\QueuedRuns;
use App\Runners\RunConclusion;
use App\Services\ReviewFiling;
use App\Services\ReviewInstructions;
use App\Services\ReviewOpening;
use App\Services\ReviewPublisher;
use App\Services\ReviewSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One review of a pull request from its diff alone, done by this server itself.
 *
 *  1. The review is opened: whether to review at all, the lock, the run's record
 *     and everything a reviewer reads about the pull request.
 *  2. The diff is read from the repository's host.
 *  3. The tenant's AI provider is asked for the report, in one request.
 *  4. The report is filed, which posts it on the pull request.
 *  5. The review lock is released, and the pull request is told how it ended.
 *
 * Nothing of the repository is cloned and no code runs, so there is nothing to
 * sandbox: what comes back from the provider is data, and it is checked as the
 * report of any other reviewer is.
 */
class DiffReview
{
    /** What the run is recorded as having been reviewed by. */
    public const AGENT = 'diff';

    public function __construct(
        protected ReviewOpening $opening,
        protected ReviewFiling $filing,
        protected RunConclusion $conclusion,
        protected DiffRunner $runner,
        protected RunLog $log,
        protected QueuedRuns $queued,
    ) {}

    /**
     * @param  Trial|null  $trial  a review to try a setup with, which is kept off the pull request
     */
    public function run(Repository $repository, string $branch, ?string $commit, int $build, ?int $waited = null, ?Trial $trial = null): void
    {
        $log = $this->log->writer((int) $repository->getKey(), $build);
        $opened = null;

        try {
            // A trial names its pull request, which may be merged by now.
            $pullRequest = $trial === null
                ? $repository->sourceControl()->findOpenPullRequestByBranch($branch)
                : $repository->sourceControl()->getPullRequest((string) $trial->pullRequestId);

            if ($pullRequest === null) {
                $log?->note("No open pull request from {$branch}; nothing to review.");
                // Merged or declined while it waited: nothing went wrong.
                $this->queued->close($repository, $build, ReviewRun::STATUS_SKIPPED, 'Nothing to review');

                return;
            }

            // The run is recorded and locked by its commit, and a pull request
            // names its head by the start of it.
            // A merged pull request is recorded on the commit it went in as.
            $head = (string) ($commit ?? ($trial === null ? null : $trial->mergeCommit) ?? $pullRequest['source']['commit']['hash'] ?? '');
            $commit = $repository->sourceControl()->resolveCommit($head) ?? $head;

            if ($commit === '') {
                $log?->note("The pull request from {$branch} names no commit; nothing to review.");
                $this->queued->close($repository, $build, ReviewRun::STATUS_SKIPPED, 'Nothing to review');

                return;
            }

            [$connection, $model, $effort] = $this->reviewer($repository, (string) $pullRequest['id']);

            // A trial's own model and effort, where it asked for one.
            $model = filled($trial?->model) ? $trial->model : $model;
            $effort = filled($trial?->effort) ? $trial->effort : $effort;

            if ($trial !== null) {
                $log?->note('Trial: a review of the whole pull request from its diff, recorded here and not posted. No lock, drafts included.');
            }

            $context = $this->opening->open($repository, [
                'pull_request_id' => (int) $pullRequest['id'],
                'commit' => $commit,
                // A trial holds no lock: it is not the review of the pull request.
                'lock' => $trial === null ? (string) $build : null,
                'build' => (string) $build,
                'agent' => self::AGENT,
                'runner' => DiffRunner::NAME,
                'provider' => $connection->driver,
                'model' => $model,
                'effort' => $effort,
                'custom' => $trial !== null,
                'force' => $trial !== null,
                'drafts' => $trial !== null,
                'instructions' => ReviewInstructions::CONTRACT,
            ]);

            if (($context['body']['skip'] ?? false) === true) {
                $log?->note('Not reviewed: '.match ($context['body']['reason'] ?? null) {
                    'in-progress' => 'another review of this pull request is running; this one waits for it.',
                    'draft' => 'the pull request is a draft.',
                    'already-reviewed', 'unchanged' => 'this commit was reviewed and nothing was said since.',
                    default => (string) ($context['body']['reason'] ?? 'the server said to skip it').'.',
                });

                $this->queued->close($repository, $build, ReviewRun::STATUS_SKIPPED, 'Nothing to review');

                return;
            }

            $opened = $commit;
            $context = $context['body'];
            // What the team set for its reviews: the language, and whether the ticket is graded.
            $context['settings'] = ReviewSettings::options(ReviewSettings::variables($repository, null, (string) $pullRequest['id']));

            if ($trial?->ignoreExisting === true) {
                // As though nobody had reviewed: two setups are compared on the same
                // work. The ticket stays, which is the brief and not the history.
                $context = ['comments' => [], 'findings' => [], 'last_reviewed_commit' => null, 'changes' => [], 'before_release' => [], 'manual_review' => [], 'handover' => null] + $context;
            }

            // Nothing of a trial reaches the pull request, its commit's status included.
            if ($trial === null) {
                $this->conclusion->show($repository, $commit, $build, BuildState::InProgress, 'Reviewing');
            }

            if ($waited !== null) {
                ReviewRun::query()
                    ->where('repository_id', $repository->getKey())
                    ->where('commit', $commit)
                    ->where('build_number', $build)
                    ->update(['queue_seconds' => $waited]);
            }

            $log?->note("Reading the diff of pull request {$pullRequest['id']}.");
            $diff = Diff::from($repository->sourceControl()->getPullRequestDiff((string) $pullRequest['id']), (int) config('review.diff.max_bytes'));

            foreach ($diff->leftOut as $file) {
                $log?->note("  Left out: {$file}");
            }

            /** @var LlmDriver $driver */
            $driver = $connection->driver();

            $log?->note('Asking '.$driver->label().' for the review'.($model === null ? '' : " ({$model})").', with '.number_format(strlen($diff->text)).' bytes of diff.');

            try {
                $answer = $driver->review($connection, $this->instructions($context), $this->material($context, $diff), $model, $effort);
            } catch (ConnectionException $exception) {
                // Only this call is the provider's: the host's own failures keep their name below.
                $log?->note("The connection to {$driver->label()} was lost, or the answer took longer than the ".config('review.diff.timeout').' seconds a review may take.');

                throw $exception;
            }

            $log?->note("{$answer->model} answered: ".count($answer->report['findings'] ?? []).' finding(s), '.number_format($answer->usage['input'] + $answer->usage['cache_read'] + $answer->usage['cache_creation']).' tokens in and '.number_format($answer->usage['output']).' out.');

            $filed = $this->filing->file($repository, $this->report($answer, $diff, $branch, $commit, $build, (int) $pullRequest['id'], $connection, $effort) + ($trial === null ? [] : ['custom' => true]) + ($trial === null && $repository->sourceControl()->publishes() ? [] : ['publish' => false]));

            $log?->note('Filed: '.(($filed['publish']['published'] ?? false) ? 'the review is on the pull request.' : 'not published ('.($filed['publish']['reason'] ?? 'no reason given').').'));
        } catch (ReviewerFailed $exception) {
            // The provider's own answer, which says what to change: the log is
            // where a person looks for why a review did not finish.
            $log?->note('The review did not finish: '.$exception->getMessage());
        } catch (Throwable $exception) {
            $log?->note('The review did not finish: '.class_basename($exception).'.');

            throw $exception;
        } finally {
            $log?->flush();

            if ($opened !== null) {
                $this->conclusion->conclude($repository, $branch, $opened, $build, reviewing: $trial === null, trial: $trial !== null);
            }

            // Ended before it began, on an error: no provider to ask, or a host that failed.
            $this->queued->close($repository, $build, ReviewRun::STATUS_FAILED, 'The review did not start');
            $this->runner->markEnded($repository, $build);
        }
    }

    /**
     * A run whose job died: nothing is left to do but let go of what it held.
     */
    public function abandon(Repository $repository, string $branch, int $build, bool $trial = false): void
    {
        $commit = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->value('commit');

        if (is_string($commit)) {
            // A trial took no lock and showed nothing on the pull request, so it
            // has neither to give back: only its record is closed.
            $this->conclusion->conclude($repository, $branch, $commit, $build, reviewing: ! $trial, trial: $trial);
        }

        $this->queued->close($repository, $build, ReviewRun::STATUS_FAILED, 'The review did not start');
        $this->runner->markEnded($repository, $build);
    }

    /**
     * The connection to ask, and the model and effort the repository set for it.
     *
     * The reviewer the settings pick for this pull request says which provider:
     * Codex means OpenAI, anything else Anthropic. A tenant that has not got the
     * one that was picked is asked through the provider it connected first,
     * whichever that is.
     *
     * @return array{Connection, string|null, string|null}
     *
     * @throws ReviewerFailed when the tenant has no AI provider
     */
    protected function reviewer(Repository $repository, string $pullRequestId): array
    {
        $settings = ReviewSettings::variables($repository, null, $pullRequestId);
        $wantsOpenAi = ($settings['REVIEW_PROVIDER'] ?? null) === ReviewProvider::CodexCli->value;

        $connections = $repository->tenant->connections()->where('kind', ConnectionKind::Llm)->oldest('id')->get();
        $wanted = $wantsOpenAi ? OpenAiDriver::class : AnthropicDriver::class;
        $connection = $connections->first(fn (Connection $connection): bool => $connection->driver() instanceof $wanted)
            ?? $connections->first();

        if ($connection === null) {
            throw new ReviewerFailed('The tenant has no AI provider connection to ask.');
        }

        $prefix = $connection->driver() instanceof OpenAiDriver ? 'CODEX' : 'CLAUDE';

        return [$connection, $settings["{$prefix}_REVIEW_MODEL"] ?? null, $settings["{$prefix}_REVIEW_EFFORT"] ?? null];
    }

    /**
     * What a review is and how to write one: this runner's own instructions, and
     * the tenant's lenses and stack guides. Not the method, which tells a reviewer
     * how to read a repository it has no access to here.
     *
     * @param  array<string, mixed>  $context
     */
    protected function instructions(array $context): string
    {
        $parts = [File::get(resource_path('review/diff/instructions.md'))];

        foreach ($context['instructions']['files'] ?? [] as $file) {
            // Not the structure of a container review: this runner has its own above.
            if (($file['key'] ?? null) !== ReviewInstructions::STRUCTURE && filled($file['body'] ?? null)) {
                $parts[] = "# {$file['title']}\n\n{$file['body']}";
            }
        }

        // What the team set: the language it reads, and whether the ticket is graded.
        $options = $context['settings'] ?? [];

        if (($options['language'] ?? 'English') !== 'English') {
            $parts[] = "Write the fields meant for people (title, details, summary, changes, before_release) in {$options['language']}. Keep code, file paths, field names and the report's keys in English.";
        }

        if (($options['grade_ticket'] ?? true) === false) {
            $parts[] = 'Do not grade the change against the ticket\'s acceptance criteria: read the ticket for intent only.';
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * The pull request to review: what the server knows of it, and its diff.
     *
     * @param  array<string, mixed>  $context
     */
    protected function material(array $context, Diff $diff): string
    {
        $about = array_intersect_key($context, array_flip(['pull_request', 'ticket', 'findings', 'comments', 'changes', 'before_release', 'manual_review', 'last_reviewed_commit', 'handover']));

        $parts = [
            "The pull request, as JSON:\n\n".json_encode($about, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        if ($diff->leftOut !== []) {
            $parts[] = "These files are part of the pull request and their diff is not below:\n\n- ".implode("\n- ", $diff->leftOut)
                .($diff->cutShort() ? "\n\nSay in the summary which of them were not reviewed because the diff was too large." : '');
        }

        $parts[] = "The diff of the whole pull request against {$context['pull_request']['destination_branch']}:\n\n".$diff->text;

        return implode("\n\n", $parts);
    }

    /**
     * The answer as the report every reviewer files.
     *
     * @return array<string, mixed>
     */
    protected function report(Answer $answer, Diff $diff, string $branch, string $commit, int $build, int $pullRequestId, Connection $connection, ?string $effort): array
    {
        $report = $answer->report;
        $findings = array_values(array_filter(is_array($report['findings'] ?? null) ? $report['findings'] : [], is_array(...)));

        // What the threshold says, not what the reviewer wrote: the approval or the
        // request for changes follows from it.
        $blocking = array_filter($findings, fn (array $finding): bool => ReviewPublisher::blocks($finding, ReviewPublisher::DEFAULT_FAIL_ON));

        $lines = fn (string $field): array => array_values(array_filter(is_array($report[$field] ?? null) ? $report[$field] : [], is_string(...)));

        return [
            'branch' => $branch,
            'commit' => $commit,
            'build' => $build,
            'pull_request_id' => $pullRequestId,
            'status' => ReviewRun::STATUS_FINISHED,
            'result' => $blocking === [] ? 'PASSED' : 'FAILED',
            'summary' => is_string($report['summary'] ?? null) ? $report['summary'] : '',
            'findings' => $findings,
            'changes' => $lines('changes'),
            'before_release' => $lines('before_release'),
            // Absent keeps what the last review left for a person; a list replaces it.
            'manual_review' => is_array($report['manual_review'] ?? null) ? $lines('manual_review') : null,
            // The findings go on the pull request as comments: there is no step here
            // to put them in the diff as annotations.
            'mode' => 'comment',
            'fail_on' => ReviewPublisher::DEFAULT_FAIL_ON,
            'agent' => self::AGENT,
            'provider' => $connection->driver,
            'model' => $answer->model,
            'effort' => $effort,
            'usage' => [$answer->model => $answer->usage],
        ];
    }
}
