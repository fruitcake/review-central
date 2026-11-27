<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewFiling;
use App\Services\ReviewPublisher;
use App\Services\ReviewSettings;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The end of a container review, done by the worker itself: reads the report the
 * reviewer wrote, files it, which posts it on the pull request, and puts a Code
 * Insights report on the commit.
 *
 * What the reviewer wrote is read here as data and nothing else: a
 * plain file of a sane size, checked to be a report before anything is filed.
 */
class Report
{
    /** The most a file the reviewer wrote may hold before it is not read at all. */
    protected const LARGEST = 5_000_000;

    /** Annotations Bitbucket takes in one call, and in one report. */
    protected const BATCH = 100;

    protected const MOST_ANNOTATIONS = 1000;

    public function __construct(protected ReviewFiling $filing) {}

    /**
     * @param  string  $directory  the checkout, where the reviewer left its files
     * @param  RunLogWriter|null  $log  where the run's page reads what was published
     * @return bool whether a review was filed
     */
    public function run(Repository $repository, string $branch, string $commit, int $build, int $pullRequestId, string $directory, ?Trial $trial = null, ?RunLogWriter $log = null): bool
    {
        $say = fn (string $line) => $log?->note($line);
        $report = $this->json("{$directory}/ai-review.json");

        if ($report === null) {
            $say('The reviewer left no ai-review.json that can be read as a report; nothing is published.');

            return false;
        }

        // A review the reviewer was told to stop: nothing to publish.
        if (($report['skipped'] ?? false) === true) {
            $say('The review was skipped: '.(is_string($report['summary'] ?? null) ? $report['summary'] : 'no reason given'));

            return false;
        }

        $findings = $report['findings'] ?? [];

        // Findings that are not a list of objects cannot be published as they are,
        // and dropping them would publish a clean review over whatever was found.
        if (! is_array($findings) || ! array_is_list($findings) || array_filter($findings, fn (mixed $finding): bool => ! is_array($finding)) !== []) {
            $say('ai-review.json is not a report this can publish: its findings are not a list of findings. Nothing is published.');

            return false;
        }

        $context = $this->json("{$directory}/review-context.json") ?? [];
        $findings = $this->numberedOn($findings, (int) ($context['ignored_existing']['highest_finding_id'] ?? 0), $say);
        $usage = $this->json("{$directory}/ai-usage.json") ?? [];
        $lines = fn (string $field): array => array_values(array_filter(is_array($report[$field] ?? null) ? $report[$field] : [], is_string(...)));

        $filed = $this->filing->file($repository, [
            'branch' => $branch,
            'commit' => $commit,
            'build' => $build,
            'pull_request_id' => $pullRequestId,
            'status' => ReviewRun::STATUS_FINISHED,
            'summary' => is_string($report['summary'] ?? null) ? $report['summary'] : '',
            'findings' => $findings,
            'changes' => $lines('changes'),
            'before_release' => $lines('before_release'),
            // Passed on as written: absent keeps what the last review left for a
            // person, an empty list clears it.
            'manual_review' => is_array($report['manual_review'] ?? null) ? $lines('manual_review') : null,
            'usage' => is_array($usage['usage'] ?? null) ? $usage['usage'] : [],
            // Written in the sandbox, where the pull request's code ran: a cost below zero is no cost.
            'cost' => is_numeric($usage['cost'] ?? null) && (float) $usage['cost'] >= 0 ? (float) $usage['cost'] : null,
            'duration' => $this->duration("{$directory}/.review-started"),
            'mode' => 'both',
            'create_tasks' => true,
            // What the precheck told the reviewer, which its second check went by.
            'fail_on' => ReviewRun::query()->where('repository_id', $repository->getKey())->where('commit', $commit)->where('build_number', $build)->value('fail_on')
                ?? ReviewSettings::failOn($repository),
            // A trial is recorded in full and kept off the pull request, and so is
            // any review of a repository whose host is only read from.
            'publish' => $trial === null && $repository->sourceControl()->publishes(),
            'custom' => $trial !== null,
        ]);

        $run = ReviewRun::query()->whereKey($filed['id'])->first();
        $this->print($run, $say);

        $publish = $filed['publish'];

        $say(match (true) {
            $trial !== null => 'Trial: the review is recorded here and kept off the pull request.',
            ($publish['published'] ?? false) === true => 'Published to the pull request: summary '.($publish['summary'] ?? '-').', comments '.json_encode($publish['comments'] ?? []).', tasks '.json_encode($publish['tasks'] ?? []).'. Verdict: '.($publish['verdict'] ?? 'none').'.',
            default => 'Not published: '.($publish['reason'] ?? 'no reason given').'.',
        });

        // A thread that would not resolve reads as fixed and still open on the pull
        // request; said here, with the host's reason, so it is not looked for in
        // the application's log.
        if (($publish['comments']['not_resolved'] ?? 0) > 0) {
            $say(str_contains((string) ($publish['comments']['resolve_error'] ?? ''), 'Resource not accessible by')
                // Optional, and left out on purpose where it is: no warning.
                ? "{$publish['comments']['not_resolved']} finding thread(s) left open: the finding says it is done on its first line and in the reply. Resolving the thread too needs Contents: Read and write on the GitHub App or token, which is optional."
                : "{$publish['comments']['not_resolved']} finding thread(s) could not be resolved on the pull request: ".($publish['comments']['resolve_error'] ?? 'no reason given'));
        }

        if ($trial === null && $run !== null && $run->status === ReviewRun::STATUS_FINISHED) {
            $this->insights($repository, $run, $say);
        }

        return $run !== null && in_array($run->status, [ReviewRun::STATUS_FINISHED, ReviewRun::STATUS_UNPUBLISHED], true);
    }

    /**
     * The review as a person reads it, in the run's log: a trial publishes nothing
     * on the pull request, and then the log is where the review is read.
     *
     * @param  callable(string): mixed  $say
     */
    protected function print(?ReviewRun $run, callable $say): void
    {
        if ($run === null) {
            return;
        }

        $failOn = ReviewPublisher::failOnSpec($run->getAttribute('fail_on'));
        $open = array_values(array_filter($run->findings ?? [], fn (array $finding): bool => in_array($finding['status'] ?? 'new', ['new', 'open'], true)));
        $blocking = array_values(array_filter($open, fn (array $finding): bool => ReviewPublisher::blocks($finding, $failOn)));

        $say('==================== AI review ====================');
        $say(match (true) {
            $open === [] => 'No open findings.',
            $blocking === [] => 'Nothing blocks the merge: '.count($open).' open finding(s), none at the threshold.',
            default => count($blocking).' of '.count($open).' open finding(s) block the merge.',
        });
        $say("Threshold: {$failOn}");

        if (filled($run->summary)) {
            $say('');
            $say((string) $run->summary);
        }

        foreach ($open as $finding) {
            $say('');
            $say('  '.str_pad((string) ($finding['severity'] ?? '?'), 8).($finding['path'] ?? '(no file)').(isset($finding['line']) ? ":{$finding['line']}" : '')
                .(in_array($finding, $blocking, true) ? '  [blocks]' : ''));
            $say('          '.($finding['title'] ?? $finding['id'] ?? 'Finding'));

            if (filled($finding['details'] ?? null)) {
                $say('          '.str_replace("\n", "\n          ", (string) $finding['details']));
            }
        }

        $say('===================================================');
    }

    /**
     * Puts the review on the commit as a Code Insights report, with its open
     * findings in the diff as annotations. Best effort: the review is on the pull
     * request by now, and this is one more place to read it.
     *
     * @param  callable(string): mixed  $say
     */
    protected function insights(Repository $repository, ReviewRun $run, callable $say): void
    {
        $failOn = ReviewPublisher::failOnSpec($run->getAttribute('fail_on'));
        $open = array_values(array_filter($run->findings ?? [], fn (array $finding): bool => in_array($finding['status'] ?? 'new', ['new', 'open'], true)));
        $blocking = count(array_filter($open, fn (array $finding): bool => ReviewPublisher::blocks($finding, $failOn)));
        $fixed = count(array_filter($run->findings ?? [], fn (array $finding): bool => ($finding['status'] ?? null) === 'fixed'));
        $manual = count($run->manual_review ?? []);

        try {
            $repository->sourceControl()->putCommitReport($run->commit, [
                'title' => 'AI Review',
                'details' => filled($run->summary) ? (string) $run->summary : 'The review wrote no summary.',
                'report_type' => 'BUG',
                'reporter' => (string) ($run->agent ?? 'ai-review'),
                // Passed with something left for a person reads as pending, not as passed.
                'result' => match (true) {
                    $blocking > 0 => 'FAILED',
                    $manual > 0 => 'PENDING',
                    default => 'PASSED',
                },
                'data' => [
                    ['title' => 'Blocking findings', 'type' => 'NUMBER', 'value' => $blocking],
                    ['title' => 'Open findings', 'type' => 'NUMBER', 'value' => count($open)],
                    ['title' => 'Fixed', 'type' => 'NUMBER', 'value' => $fixed],
                    ['title' => 'For a person to check', 'type' => 'NUMBER', 'value' => $manual],
                    ['title' => 'Threshold', 'type' => 'TEXT', 'value' => $failOn],
                ],
            ]);

            // An annotation needs a file to land on.
            $annotations = array_slice(array_values(array_filter(array_map($this->annotation(...), $open))), 0, self::MOST_ANNOTATIONS);
            $landed = null;

            foreach (array_chunk($annotations, self::BATCH) as $batch) {
                $landed = $repository->sourceControl()->addCommitReportAnnotations($run->commit, $batch);
            }

            $say('Code Insights: the report is on the commit'.($annotations === [] ? '.' : ', with '.count($annotations).' annotation(s)'.($landed === null ? '.' : "; Bitbucket now holds {$landed}.")));
        } catch (Throwable $exception) {
            report($exception);
            $say('Code Insights: the report was not published, or not all of it ('.mb_substr($exception->getMessage(), 0, 300).'). The review itself is on the pull request.');
        }
    }

    /**
     * A finding as Bitbucket shows it in the diff, or null when it names no file.
     *
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>|null
     */
    protected function annotation(array $finding): ?array
    {
        if (! filled($finding['path'] ?? null)) {
            return null;
        }

        // A finding the review could not finish proving keeps its severity and must
        // not read in the diff as established; a pre-existing one says why it is
        // there on a change that did not cause it.
        [$prefix, $note] = match (true) {
            ($finding['introduced'] ?? null) === false => ['Pre-existing: ', "This was already wrong before this pull request; it is reported so someone can pick it up, and does not block the merge.\n\n"],
            (int) ($finding['confidence'] ?? 10) < 8 => ['Unconfirmed: ', "The review could not fully establish this finding; it is reported for a person to judge.\n\n"],
            default => ['', ''],
        };

        $title = $prefix.(string) ($finding['title'] ?? $finding['id'] ?? 'Finding');
        $severity = strtoupper((string) ($finding['severity'] ?? ''));

        return [
            'external_id' => 'ai-review-'.(string) ($finding['id'] ?? 'x'),
            'title' => $title,
            'summary' => $title,
            'details' => $note.(string) ($finding['details'] ?? $finding['title'] ?? ''),
            // Bitbucket knows three kinds.
            'annotation_type' => match ($finding['category'] ?? '') {
                'security' => 'VULNERABILITY',
                'maintainability', 'tests' => 'CODE_SMELL',
                default => 'BUG',
            },
            'severity' => in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) ? $severity : 'LOW',
            'path' => (string) $finding['path'],
        ] + (is_int($finding['line'] ?? null) ? ['line' => $finding['line']] : []);
    }

    /**
     * A trial that left out what is on the pull request started its findings at F1
     * again. They are numbered on from the highest id that is there, so they are
     * never taken for the findings that really are.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  callable(string): mixed  $say
     * @return array<int, array<string, mixed>>
     */
    protected function numberedOn(array $findings, int $offset, callable $say): array
    {
        if ($offset <= 0) {
            return $findings;
        }

        $say("Numbered this run's findings after F{$offset}, the highest id already on the pull request.");

        return array_map(function (array $finding) use ($offset): array {
            if (preg_match('/^F(\d+)$/', (string) ($finding['id'] ?? ''), $matches) === 1) {
                $finding['id'] = 'F'.((int) $matches[1] + $offset);
            }

            return $finding;
        }, $findings);
    }

    /**
     * How long the review itself took: since the moment the reviewer started, which
     * the reviewer step marks with a file.
     */
    protected function duration(string $started): ?int
    {
        $at = is_file($started) && ! is_link($started) ? filemtime($started) : false;

        return $at === false ? null : max(0, time() - $at);
    }

    /**
     * A file the reviewer step wrote, read as data: a plain file, never a link to
     * something of the worker's, and not larger than a report can be.
     *
     * @return array<string, mixed>|null null when it is not there or is no JSON object
     */
    protected function json(string $path): ?array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > self::LARGEST) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
