<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Enums\ReviewProvider;
use App\Exceptions\ReviewStepFailed;
use App\Models\Repository;
use App\Runners\ContainerRunner;
use App\Services\ReviewCredentials;
use App\Services\ReviewInstructions;
use App\Services\ReviewOpening;
use App\Services\ReviewPublisher;
use App\Services\ReviewSettings;
use Illuminate\Support\Facades\File;

/**
 * The start of a container review, done by the worker itself: which reviewer runs,
 * whether there is anything to review, and what the reviewer reads.
 *
 * It decides, gathers and locks in one go ({@see ReviewOpening}), and writes what
 * it gathered into the checkout for the reviewer step:
 *
 *   review-context.json     the pull request, its comments, the earlier findings,
 *                           and the settings the review branches on
 *   review-ticket.md        the ticket, when the title names one
 *   .review-instructions/   the review's guidance and the stack guides
 *
 * This was a step in a container of its own that asked this server over HTTP. It
 * read no code then and reads none now, so a review that is turned away costs no
 * container.
 */
class Precheck
{
    /** The reviewers a container review can run. */
    protected const REVIEWERS = [ReviewProvider::ClaudeCode, ReviewProvider::CodexCli, ReviewProvider::Ocr];

    /** Names a reviewer went by before, still stored in settings. */
    protected const OLDER_NAMES = ['claude' => 'claude-code', 'claude-acp' => 'claude-code', 'claude-native' => 'claude-code', 'codex' => 'codex-cli', 'codex-acp' => 'codex-cli', 'codex-native' => 'codex-cli'];

    /** What a model, an effort or an advisor may be made of: it goes into a config file and the log. */
    protected const SETTING = '/^[A-Za-z0-9._:\/@\[\]-]+$/';

    public function __construct(
        protected ReviewOpening $opening,
        protected ReviewCredentials $credentials,
    ) {}

    /**
     * @param  string  $directory  the checkout, where the reviewer's files are written
     * @param  RunLogWriter|null  $log  where the run's page reads what was decided
     * @return array{REVIEW_NEEDED: string, REVIEW_REVIEWER: string, REVIEW_PR_ID: string, REVIEW_DESTINATION_BRANCH: string} what the steps after this one are handed
     *
     * @throws ReviewStepFailed when the review cannot start: no reviewer to run, or no context
     */
    public function run(Repository $repository, string $branch, string $commit, int $build, string $directory, ?Trial $trial = null, ?RunLogWriter $log = null, string $runner = ContainerRunner::NAME): array
    {
        $say = fn (string $line) => $log?->note($line);

        $available = $this->credentials->reviewers($repository->tenant);
        ReviewSettings::recordAvailable($repository, $available);
        $say('Reviewers this tenant has a key for: '.(implode(', ', $available) ?: 'none').'.');

        // What the server sets for this repository. A pull request keeps its
        // reviewer: the choice is made from its branch, which never changes.
        $settings = array_filter(
            ReviewSettings::variables($repository, $available, $trial === null ? null : (string) $trial->pullRequestId, $branch),
            fn (string $value): bool => preg_match(self::SETTING, $value) === 1,
        );

        $reviewer = $this->reviewer($settings, $available, $trial, $say);

        // Nothing set is the platform's effort, said out loud: the review then
        // records what it ran at, and a reviewer's own default can change unseen.
        $default = config("review.default_effort.{$reviewer->value}");

        if (is_string($default) && $default !== '') {
            $settings[self::prefix($reviewer).'_REVIEW_EFFORT'] ??= $default;
        }
        [$model, $effort, $advisor] = $this->setup($reviewer, $settings, $trial);

        $say("Reviewer {$reviewer->value}".($model === null ? '' : ", model {$model}").($effort === null ? '' : ", effort {$effort}"));

        if ($trial !== null) {
            $say('Trial: a fresh review of the whole pull request, recorded here and not posted. Forced, drafts included, no lock.');
        }

        $opened = $this->opening->open($repository, [
            'pull_request_id' => $trial?->pullRequestId,
            'branch' => $branch,
            'commit' => $commit,
            // A trial holds no lock: it is not the review of the pull request.
            'lock' => $trial === null ? (string) $build : null,
            'build' => (string) $build,
            'mode' => 'both',
            'fail_on' => ReviewPublisher::DEFAULT_FAIL_ON,
            'model' => $model,
            'agent' => $reviewer->value,
            'runner' => $runner,
            'provider' => match ($reviewer) {
                ReviewProvider::ClaudeCode => 'anthropic',
                ReviewProvider::CodexCli => 'openai',
                default => null,
            },
            'effort' => $effort,
            'advisor' => $advisor,
            'custom' => $trial !== null,
            // A trial reviews a commit that was reviewed, and a draft; it never
            // overrides a superseded commit.
            'force' => $trial !== null,
            'drafts' => $trial !== null,
            'create_tasks' => true,
            'instructions' => ReviewInstructions::CONTRACT,
        ]);

        $context = $opened['body'];

        if ($opened['status'] === 409) {
            $say('Build '.($context['locked_by'] ?? '?').' is already reviewing this pull request; this commit waits and is started again when that one is done.');

            return $this->outcome(false);
        }

        if ($opened['status'] !== 200) {
            $say('The review cannot start: '.($context['message'] ?? "the context could not be read ({$opened['status']})").'.');

            throw new ReviewStepFailed('The precheck could not open the review: '.($context['message'] ?? "status {$opened['status']}"));
        }

        if (($context['skip'] ?? false) === true) {
            $say(match ($context['reason'] ?? null) {
                'draft' => 'The pull request is a draft; not reviewed.',
                'already-reviewed' => "Commit {$commit} was already reviewed and nobody has said anything since; not reviewed again. A comment on the pull request, or a reply to a finding, has it reviewed again.",
                'unchanged' => 'Nothing that decides this review has changed since it last ran: the same commit, ticket and conversation. Not reviewed again.',
                'no-open-pull-request' => "No open pull request for {$branch}; nothing to review.",
                'superseded' => "Another commit was pushed meanwhile, so {$commit} is no longer the pull request's head; its own review covers this one.",
                'in-progress' => 'Another review of this pull request is running.',
                default => 'Not reviewed: '.($context['reason'] ?? 'no reason given').'.',
            });

            return $this->outcome(false);
        }

        $stack = array_values(array_filter($context['stack'] ?? [], is_string(...)));
        unset($context['stack']);
        $say('Stack: '.(implode(' ', $stack) ?: 'none').'.');

        // The settings the review branches on, next to the data it reviews. No
        // secret is here: those reach the reviewer in its environment.
        $context['settings'] = [
            'mode' => 'both',
            'create_tasks' => true,
            // A draft that was not skipped is one to review: a trial, or a /review comment on it.
            'review_drafts' => $trial !== null || ($context['pull_request']['draft'] ?? false) === true,
            'fail_on' => ReviewPublisher::DEFAULT_FAIL_ON,
            'commit' => $commit,
            'server_url' => rtrim((string) (config('review.container.server_url') ?: config('app.url')), '/'),
            'server_key_available' => true,
            'agent' => $reviewer->value,
            'stack' => $stack,
            // What the server set, for the reviewer step to apply.
            'server_variables' => (object) $settings,
        ] + ReviewSettings::options($settings);

        if ($trial?->ignoreExisting === true) {
            $context = $this->withoutWhatIsThere($context, $say);
        } elseif ($trial !== null) {
            $say('Trial with what is already on the pull request: its comments and earlier findings are read.');
        }

        $this->take($directory);
        $context = $this->writeTicket($directory, $context, $say);
        $context = $this->writeInstructions($directory, $context, $say);
        $this->put("{$directory}/review-context.json", json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $say('Pull request '.($context['pull_request']['id'] ?? '?').': '.count($context['comments'] ?? []).' comment(s), '.count($context['findings'] ?? []).' previous finding(s), last reviewed '.($context['last_reviewed_commit'] ?? 'never').'.');

        // What the review was given: a surprising review is nearly always explained by its input.
        $say('--- Review context: review-context.json ---');
        $say((string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $say('--- end of review-context.json ---');

        return $this->outcome(true, $reviewer, $context['pull_request']['id'] ?? null, $context['pull_request']['destination_branch'] ?? null);
    }

    /**
     * Which reviewer runs: the one a trial asks for, else the one the settings pick
     * where the tenant has its key, else whichever the keys allow, Claude first.
     *
     * @param  array<string, string>  $settings
     * @param  array<int, string>  $available
     * @param  callable(string): mixed  $say
     *
     * @throws ReviewStepFailed
     */
    protected function reviewer(array $settings, array $available, ?Trial $trial, callable $say): ReviewProvider
    {
        $asked = $trial !== null && $trial->provider !== null ? $trial->provider : ($settings['REVIEW_PROVIDER'] ?? null);
        $name = self::OLDER_NAMES[$asked] ?? $asked;
        $reviewer = is_string($name) ? ReviewProvider::tryFrom($name) : null;

        $runnable = [...self::REVIEWERS, ...($trial?->provider !== null ? ReviewProvider::onTrial() : [])];

        if ($asked !== null && ($reviewer === null || ! in_array($reviewer, $runnable, true))) {
            $say("The reviewer \"{$asked}\" is not one a container review can run (".implode(', ', array_column($runnable, 'value')).'). No review runs until that is fixed.');

            throw new ReviewStepFailed("The reviewer \"{$asked}\" is not one a container review can run.");
        }

        // A reviewer that was set for every repository at once runs only where the
        // tenant has its key. One a trial asks for is honoured, and fails at the
        // reviewer when the key is missing.
        if ($reviewer !== null && ($trial?->provider !== null || in_array($reviewer->value, $available, true))) {
            return $reviewer;
        }

        if ($reviewer !== null) {
            $say("The settings pick {$reviewer->value}, but this tenant has no key for it; the keys decide.");
        }

        foreach (self::REVIEWERS as $candidate) {
            if (in_array($candidate->value, $available, true)) {
                return $candidate;
            }
        }

        $say('This tenant has no AI provider key, so there is no reviewer to run. Add one under AI providers.');

        throw new ReviewStepFailed('The tenant has no AI provider key to review with.');
    }

    /**
     * What the reviewer's settings are called: CLAUDE_REVIEW_MODEL and the like.
     */
    protected static function prefix(ReviewProvider $reviewer): string
    {
        return match ($reviewer) {
            ReviewProvider::ClaudeCode => 'CLAUDE',
            ReviewProvider::CodexCli => 'CODEX',
            ReviewProvider::Pi => 'PI',
            default => 'OCR',
        };
    }

    /**
     * The model, effort and advisor the reviewer will run with, as far as the server
     * sets them: a trial's own choice first.
     *
     * @param  array<string, string>  $settings
     * @return array{string|null, string|null, string|null}
     */
    protected function setup(ReviewProvider $reviewer, array $settings, ?Trial $trial): array
    {
        $prefix = self::prefix($reviewer);

        $model = filled($trial?->model) && $trial->model !== 'default' ? $trial->model : ($settings["{$prefix}_REVIEW_MODEL"] ?? null);
        $effort = filled($trial?->effort) && $trial->effort !== 'default' ? $trial->effort : ($settings["{$prefix}_REVIEW_EFFORT"] ?? null);
        $advisor = $reviewer === ReviewProvider::ClaudeCode ? ($settings['CLAUDE_REVIEW_ADVISOR'] ?? null) : null;

        return [
            $model,
            // The reviewers know no effort above xhigh.
            $effort === 'max' ? 'xhigh' : $effort,
            $advisor === 'none' ? null : $advisor,
        ];
    }

    /**
     * A trial reviews as though nobody had: no comments, no earlier findings, no
     * last reviewed commit and no handover, so two setups are compared on the same
     * work. The ticket stays, which is the brief and not the history. The highest
     * finding id is kept, so the new findings are numbered on from there.
     *
     * @param  array<string, mixed>  $context
     * @param  callable(string): mixed  $say
     * @return array<string, mixed>
     */
    protected function withoutWhatIsThere(array $context, callable $say): array
    {
        $numbers = array_map(
            fn (mixed $finding): int => is_array($finding) && preg_match('/(\d+)$/', (string) ($finding['id'] ?? ''), $matches) === 1 ? (int) $matches[1] : 0,
            $context['findings'] ?? [],
        );

        $context['ignored_existing'] = [
            'comments' => count($context['comments'] ?? []),
            'findings' => count($context['findings'] ?? []),
            'highest_finding_id' => $numbers === [] ? 0 : max($numbers),
        ];

        $context = ['comments' => [], 'findings' => [], 'last_reviewed_commit' => null, 'changes' => [], 'before_release' => [], 'manual_review' => [], 'handover' => null, 'new_activity' => false] + $context;

        // A marker line left in the description would have the run review as a follow-up after all.
        if (is_string($context['pull_request']['description'] ?? null)) {
            $context['pull_request']['description'] = implode("\n", array_filter(
                explode("\n", $context['pull_request']['description']),
                fn (string $line): bool => preg_match('/^\s*\[\/\/\]: # \(ai-(review|finding)\b/', $line) !== 1,
            ));
        }

        $say("Ignoring what is already on the pull request: {$context['ignored_existing']['comments']} comment(s) and {$context['ignored_existing']['findings']} finding(s) left out; new findings are numbered after F{$context['ignored_existing']['highest_finding_id']}.");

        return $context;
    }

    /**
     * The ticket in a file of its own: the review reads it whole, and markdown in a
     * JSON string costs tokens and legibility. The context keeps the pointer.
     *
     * @param  array<string, mixed>  $context
     * @param  callable(string): mixed  $say
     * @return array<string, mixed>
     */
    protected function writeTicket(string $directory, array $context, callable $say): array
    {
        $markdown = is_array($context['ticket'] ?? null) ? ($context['ticket']['markdown'] ?? null) : null;

        if (! is_string($markdown) || $markdown === '') {
            $say('Ticket: '.(($context['ticket'] ?? null) === null ? 'no reference in the pull request title' : (string) ($context['ticket']['error'] ?? 'none')).'.');

            return $context;
        }

        $this->put("{$directory}/review-ticket.md", $markdown);
        $context['ticket']['markdown'] = null;
        $context['ticket']['file'] = 'review-ticket.md';

        $say('Ticket '.($context['ticket']['reference'] ?? '?').' written to review-ticket.md ('.strlen($markdown).' bytes).');

        return $context;
    }

    /**
     * The method, the lenses and the stack guides, written beside the context and
     * left out of it. A set the review cannot use writes nothing: the reviewer
     * step then writes the copy its skill ships with, and its log says why.
     *
     * @param  array<string, mixed>  $context
     * @param  callable(string): mixed  $say
     * @return array<string, mixed>
     */
    protected function writeInstructions(string $directory, array $context, callable $say): array
    {
        $files = is_array($context['instructions']['files'] ?? null) ? $context['instructions']['files'] : [];

        // Only a plain file name, so a key can never write outside the directory.
        $usable = $files !== [] && collect($files)->every(fn (mixed $file): bool => is_array($file)
            && preg_match('/^[a-z0-9][a-z0-9._-]*\.md$/', (string) ($file['file'] ?? '')) === 1
            && is_string($file['body'] ?? null))
            && collect($files)->contains(fn (array $file): bool => $file['file'] === 'method.md');

        File::ensureDirectoryExists("{$directory}/.review-instructions");

        if (! $usable) {
            $say('Review instructions: none the review can use from the server; the reviewer step writes the copy its skill ships with.');

            return $context;
        }

        foreach ($files as $file) {
            $this->put("{$directory}/.review-instructions/{$file['file']}", $file['body']);
        }

        $context['instructions']['source'] = 'server';
        $context['instructions']['files'] = array_map(fn (array $file): array => array_diff_key($file, ['body' => true]), $files);

        $say("Review instructions: version {$context['instructions']['version']} (".implode(', ', array_column($files, 'file')).').');

        return $context;
    }

    /**
     * Makes the places this writes to the worker's own, whatever the pull request
     * has there: a name it committed as a link would have the worker write wherever
     * the link points.
     */
    protected function take(string $directory): void
    {
        foreach (['review-context.json', 'review-ticket.md', '.review-instructions'] as $name) {
            $path = "{$directory}/{$name}";

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                File::deleteDirectory($path);
            } else {
                File::delete($path);
            }
        }
    }

    protected function put(string $path, string $content): void
    {
        if (is_link($path)) {
            unlink($path);
        }

        File::put($path, $content);
        chmod($path, 0644);
    }

    /**
     * @return array{REVIEW_NEEDED: string, REVIEW_REVIEWER: string, REVIEW_PR_ID: string, REVIEW_DESTINATION_BRANCH: string}
     */
    protected function outcome(bool $needed, ?ReviewProvider $reviewer = null, mixed $pullRequestId = null, mixed $destination = null): array
    {
        return [
            'REVIEW_NEEDED' => $needed ? 'true' : 'false',
            'REVIEW_REVIEWER' => $needed ? (string) $reviewer?->value : '',
            'REVIEW_PR_ID' => is_numeric($pullRequestId) ? (string) $pullRequestId : '',
            'REVIEW_DESTINATION_BRANCH' => is_string($destination) ? $destination : '',
        ];
    }
}
