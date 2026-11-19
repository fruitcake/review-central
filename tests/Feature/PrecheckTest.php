<?php

use App\Exceptions\ReviewStepFailed;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Runners\Container\Precheck;
use App\Runners\Container\RunLog;
use App\Runners\Container\Trial;
use App\Runners\ContainerRunner;
use App\Runners\QueuedRuns;
use App\Services\ReviewLock;
use App\Services\ReviewSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->sha = str_repeat('a', 40);
    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_stack' => ['laravel']]);
    $this->work = storage_path('framework/testing/precheck');
    File::deleteDirectory($this->work);
    File::ensureDirectoryExists($this->work);

    ReviewInstruction::query()->create(['key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => 'Review carefully.']);
    ReviewInstruction::query()->create(['key' => 'stack-laravel', 'title' => 'Laravel', 'stack' => 'laravel', 'position' => 30, 'body' => 'Mind the tenant scope.']);

    $this->fakeTicket("# SHOP-12\n\nThe export has to be per company.");

    $this->pullRequest = function (array $attributes = [], array $comments = []): void {
        $pullRequest = $attributes + [
            'id' => 570, 'title' => 'SHOP-12 Add the export', 'description' => 'Adds the export.', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
            'destination' => ['branch' => ['name' => 'main']],
        ];

        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests?*' => Http::response(['values' => [$pullRequest]]),
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => $comments]),
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response($pullRequest),
        ]);
    };

    $this->precheck = fn (?Trial $trial = null, int $build = 41): array => app(Precheck::class)
        ->run($this->repository, 'feature/export', $this->sha, $build, $this->work, $trial, app(RunLog::class)->writer($this->repository->id, $build));

    $this->log = fn (int $build = 41): string => File::get(app(RunLog::class)->path($this->repository->id, $build));
});

afterEach(fn () => File::deleteDirectory($this->work));

it('decides, locks and writes what the reviewer reads, without a container', function () {
    ($this->pullRequest)();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewSettings::saveDefaults($this->repository->tenant, ['claude_model' => 'claude-opus-5-5', 'claude_effort' => 'high']);

    expect(($this->precheck)())->toBe(['REVIEW_NEEDED' => 'true', 'REVIEW_REVIEWER' => 'claude-code', 'REVIEW_PR_ID' => '570', 'REVIEW_DESTINATION_BRANCH' => 'main']);

    $context = File::json("{$this->work}/review-context.json");

    expect($context['pull_request']['title'])->toBe('SHOP-12 Add the export')
        ->and($context['settings'])->toMatchArray([
            'mode' => 'both', 'create_tasks' => true, 'review_drafts' => false, 'commit' => $this->sha, 'agent' => 'claude-code', 'stack' => ['laravel'],
            // What the server set, which the reviewer step applies.
            'server_variables' => ['CLAUDE_REVIEW_MODEL' => 'claude-opus-5-5', 'CLAUDE_REVIEW_EFFORT' => 'high'],
        ])
        ->and($context)->not->toHaveKey('stack')
        // The ticket and the instructions are files of their own, and the context points at them.
        ->and($context['ticket'])->toMatchArray(['markdown' => null, 'file' => 'review-ticket.md'])
        ->and(File::get("{$this->work}/review-ticket.md"))->toContain('The export has to be per company.')
        ->and($context['instructions']['source'])->toBe('server')
        ->and(array_column($context['instructions']['files'], 'file'))->toBe(['method.md', 'stack-laravel.md'])
        ->and($context['instructions']['files'][0])->not->toHaveKey('body')
        ->and(File::get("{$this->work}/.review-instructions/stack-laravel.md"))->toBe('Mind the tenant scope.');

    // The lock is this build's and the run is on record, with the reviewer it will have.
    $run = ReviewRun::query()->sole();

    expect(ReviewLock::heldBy($this->repository, 'feature/export'))->toBe('41')
        // The model is recorded when the review reports which one did the work.
        ->and($run->only(['status', 'build_number', 'agent', 'provider', 'effort', 'pull_request_id']))->toBe([
            'status' => ReviewRun::STATUS_RUNNING, 'build_number' => 41, 'agent' => 'claude-code', 'provider' => 'anthropic',
            'effort' => 'high', 'pull_request_id' => 570,
        ])
        ->and($this->repository->refresh()->review_available_providers)->toBe(['claude-code', 'ocr'])
        ->and(($this->log)())->toContain('Reviewers this tenant has a key for: claude-code, ocr.', 'Reviewer claude-code, model claude-opus-5-5, effort high', 'Stack: laravel.', 'Ticket PROJ-1 written to review-ticket.md', '--- Review context: review-context.json ---');
});

it('gives the reviewer the platform\'s effort where nothing sets one, and records it', function () {
    ($this->pullRequest)();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    config(['review.default_effort.claude-code' => 'xhigh']);

    ($this->precheck)();

    expect(File::json("{$this->work}/review-context.json")['settings']['server_variables'])->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'xhigh'])
        ->and(ReviewRun::query()->sole()->effort)->toBe('xhigh')
        ->and(($this->log)())->toContain('Reviewer claude-code, effort xhigh');
});

it('writes nothing and takes no lock when there is nothing to review, and says why', function (array $pullRequest, string $said) {
    ($this->pullRequest)($pullRequest);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    expect(($this->precheck)())->toBe(['REVIEW_NEEDED' => 'false', 'REVIEW_REVIEWER' => '', 'REVIEW_PR_ID' => '', 'REVIEW_DESTINATION_BRANCH' => ''])
        ->and(File::exists("{$this->work}/review-context.json"))->toBeFalse()
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(($this->log)())->toContain($said);
})->with([
    'a draft' => [['draft' => true], 'The pull request is a draft; not reviewed.'],
    'a head that moved on' => [['source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('b', 12)]]], 'Another commit was pushed meanwhile'],
]);

it('leaves a commit to wait when another build is reviewing the pull request', function () {
    ($this->pullRequest)();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewLock::acquire($this->repository, 'feature/export', '40');
    app(ContainerRunner::class)->markRunning($this->repository, 40);

    expect(($this->precheck)()['REVIEW_NEEDED'])->toBe('false')
        ->and(ReviewRun::query()->sole()->status)->toBe(ReviewRun::STATUS_PENDING)
        ->and(($this->log)())->toContain('Build 40 is already reviewing this pull request');
});

it('picks the reviewer the settings name where the tenant has its key, and the one the keys allow otherwise', function () {
    ($this->pullRequest)();
    Connection::factory()->openai()->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewSettings::saveDefaults($this->repository->tenant, ['providers' => [['provider' => 'claude-code', 'share' => 100]]]);

    // Set for every repository at once, and this tenant has no Anthropic key.
    expect(($this->precheck)()['REVIEW_REVIEWER'])->toBe('codex-cli')
        ->and(ReviewRun::query()->sole()->only(['agent', 'provider']))->toBe(['agent' => 'codex-cli', 'provider' => 'openai']);
});

it('fails where no review can start, and says what to fix', function () {
    ($this->pullRequest)();

    expect(fn () => ($this->precheck)())->toThrow(ReviewStepFailed::class, 'no AI provider key')
        ->and(($this->log)())->toContain('Add one under AI providers.')
        ->and(ReviewRun::query()->count())->toBe(0);
});

it('tries a setup as though nobody had reviewed, with no lock, and with the reviewer and model asked for', function () {
    $marker = "[//]: # (ai-review reviewed={$this->sha} mode=both)";
    ($this->pullRequest)(['description' => "Adds the export.\n\n{$marker}"], [[
        'id' => 9, 'content' => ['raw' => "Looks wrong.\n\n[//]: # (ai-finding id=F4 status=open severity=HIGH path=app/Export.php line=12)"],
        'created_on' => '2026-11-18T10:00:00+00:00', 'user' => ['display_name' => 'AI review'],
    ]]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Connection::factory()->openai()->create(['tenant_id' => $this->repository->tenant_id]);
    $this->reviewed($this->repository, 570, $this->sha);

    // Queued when it was asked for: the review takes that record over.
    app(QueuedRuns::class)->announceOwn($this->repository, 'feature/export', 41, 'docker', ['pull_request_id' => 570]);

    $outcome = ($this->precheck)(new Trial(pullRequestId: 570, destination: 'main', provider: 'codex-cli', model: 'gpt-test', effort: 'max'));

    $context = File::json("{$this->work}/review-context.json");

    // Already reviewed, and reviewed again: that is what a trial is for.
    expect($outcome['REVIEW_REVIEWER'])->toBe('codex-cli')
        ->and($context['findings'])->toBe([])
        ->and($context['comments'])->toBe([])
        ->and($context['last_reviewed_commit'])->toBeNull()
        ->and($context['ignored_existing'])->toBe(['comments' => 1, 'findings' => 1, 'highest_finding_id' => 4])
        ->and($context['pull_request']['description'])->not->toContain('ai-review')
        ->and($context['settings']['review_drafts'])->toBeTrue()
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(ReviewRun::query()->where('build_number', 41)->sole()->only(['custom', 'agent', 'effort', 'status']))->toBe(['custom' => true, 'agent' => 'codex-cli', 'effort' => 'xhigh', 'status' => ReviewRun::STATUS_RUNNING])
        ->and(($this->log)())->toContain('Reviewer codex-cli, model gpt-test, effort xhigh');
});

it('writes its files as its own, not through a link the pull request committed', function () {
    ($this->pullRequest)();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    // What the pull request brought: the names the worker writes, as links to elsewhere.
    $elsewhere = storage_path('framework/testing/precheck-elsewhere');
    File::ensureDirectoryExists("{$elsewhere}/instructions");
    File::put("{$elsewhere}/context.json", 'theirs');
    symlink("{$elsewhere}/context.json", "{$this->work}/review-context.json");
    symlink("{$elsewhere}/instructions", "{$this->work}/.review-instructions");

    try {
        ($this->precheck)();

        expect(is_link("{$this->work}/review-context.json"))->toBeFalse()
            ->and(is_link("{$this->work}/.review-instructions"))->toBeFalse()
            ->and(File::get("{$elsewhere}/context.json"))->toBe('theirs')
            ->and(File::files("{$elsewhere}/instructions"))->toBe([])
            ->and(File::json("{$this->work}/review-context.json")['pull_request']['id'])->toBe(570);
    } finally {
        File::deleteDirectory($elsewhere);
    }
});

it('runs a reviewer that is here to be tried for a trial that asks for it, and for nothing else', function () {
    ($this->pullRequest)();
    Connection::factory()->openai()->create(['tenant_id' => $this->repository->tenant_id]);

    $outcome = ($this->precheck)(new Trial(pullRequestId: 570, destination: 'main', provider: 'pi', model: 'gpt-test', effort: 'high'));

    // It calls either vendor's API itself, so the record names no provider of its own.
    expect($outcome['REVIEW_REVIEWER'])->toBe('pi')
        ->and(ReviewRun::query()->where('build_number', 41)->sole()->only(['custom', 'agent', 'provider', 'effort']))
        ->toBe(['custom' => true, 'agent' => 'pi', 'provider' => null, 'effort' => 'high'])
        ->and(($this->log)())->toContain('Reviewer pi, model gpt-test, effort high')
        // Not one a repository's own review is offered, or falls back to.
        ->and($this->repository->refresh()->review_available_providers)->toBe(['codex-cli', 'ocr']);

    // Set as every repository's reviewer, it is passed over: the keys decide.
    ReviewSettings::saveDefaults($this->repository->tenant, ['providers' => [['provider' => 'pi', 'share' => 100]]]);

    expect(($this->precheck)(null, 42)['REVIEW_REVIEWER'])->toBe('codex-cli');
});

it('tells the reviewer what the team chose: the language, the ticket grading and the extra passes', function () {
    ($this->pullRequest)();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    ($this->precheck)();
    $context = File::json("{$this->work}/review-context.json");

    // Nothing set: English, grading, and no extra passes.
    expect($context['settings'])->toMatchArray(['language' => 'English', 'grade_ticket' => true, 'extra_passes' => false]);

    ReviewSettings::saveDefaults($this->repository->tenant, ['language' => 'nl', 'ticket_grading' => 'false', 'extra_passes' => 'true']);
    ReviewRun::query()->delete();
    ReviewLock::release($this->repository, 'feature/export', '41');

    ($this->precheck)();

    expect(File::json("{$this->work}/review-context.json")['settings'])->toMatchArray(['language' => 'Dutch', 'grade_ticket' => false, 'extra_passes' => true])
        // And the step is told to keep them, where it would take them away.
        ->and(File::json("{$this->work}/review-context.json")['settings']['server_variables'])->toMatchArray(['REVIEW_EXTRA_PASSES' => 'true']);
});
