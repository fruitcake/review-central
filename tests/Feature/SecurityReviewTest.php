<?php

use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Filament\RunPresenter;
use App\Jobs\RunContainerReview;
use App\Jobs\RunSecurityReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\RunLog;
use App\Runners\ContainerRunner;
use App\Runners\Security\SecurityReview;
use App\Services\ReviewInstructions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['review.docker.enabled' => true, 'review.container.work_directory' => storage_path('framework/testing/review-work')]);

    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_stack' => ['laravel']]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    /**
     * Fakes git and docker; the reviewer leaves these files in the checkout.
     *
     * @param  array<string, mixed>|null  $report
     */
    $this->reviewer = function (?array $report, int $exit = 0): ArrayObject {
        $steps = new ArrayObject;

        Process::fake(function ($process) use ($steps, $report, $exit) {
            $command = (array) $process->command;
            $steps[] = ['command' => $command, 'environment' => $process->environment];

            if ($command[0] === 'git') {
                if ($command[1] === 'clone') {
                    File::ensureDirectoryExists(end($command));
                }

                return Process::result(in_array('rev-parse', $command, true) ? str_repeat('a', 40)."\n" : '');
            }

            if ($command[1] === 'run') {
                $directory = explode(':', $command[array_search('--volume', $command, true) + 1])[0];
                $steps['guides'] = array_map('basename', File::files("{$directory}/.review-instructions"));
                $steps['prompt'] = is_file("{$directory}/.review-instructions/security-prompt.md") ? File::get("{$directory}/.review-instructions/security-prompt.md") : null;

                if ($report !== null) {
                    File::put("{$directory}/ai-review.json", json_encode($report));
                    File::put("{$directory}/ai-usage.json", json_encode(['usage' => ['claude-opus-5-5' => ['input' => 1000, 'output' => 200, 'cache_read' => 0, 'cache_creation' => 0]], 'cost' => 1.5]));
                }
            }

            return Process::result(exitCode: $command[1] === 'run' ? $exit : 0);
        });

        return $steps;
    };

    $this->report = [
        'result' => 'FAILED', 'summary' => 'A Laravel shop. The public API was read in full.', 'reviewed' => str_repeat('a', 40),
        'findings' => [[
            'id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'category' => 'security', 'confidence' => 9,
            'title' => 'Any signed-in user can download another company\'s invoices', 'path' => 'app/Http/Controllers/InvoiceController.php', 'line' => 31,
            'details' => 'The id in the route selects the invoice and no policy is checked.',
        ]],
        'manual_review' => ['Whether APP_DEBUG is off in production.'],
    ];
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/review-work')));

it('starts a security review of a branch as a job of its own, with no pull request asked for', function () {
    Queue::fake();

    $build = app(ContainerRunner::class)->startSecurityReview($this->repository, 'main');

    Queue::assertPushedOn('reviews', RunSecurityReview::class, fn (RunSecurityReview $job): bool => $job->branch === 'main' && $job->build === $build && $job->repositoryId === $this->repository->id);
    Queue::assertNotPushed(RunContainerReview::class);
    // Nothing was asked of the host: there is no pull request to find.
    Http::assertNothingSent();

    // In the list from the moment it was asked for, and taken over when it begins.
    expect(ReviewRun::query()->sole()->only(['status', 'kind', 'build_number', 'custom']))
        ->toBe(['status' => ReviewRun::STATUS_QUEUED, 'kind' => 'security', 'build_number' => $build, 'custom' => true]);
});

it('reviews the whole branch in a sandbox and keeps the report, posting nothing', function () {
    $steps = ($this->reviewer)($this->report);

    app(SecurityReview::class)->run($this->repository, 'main', 41, waited: 3);

    $run = ReviewRun::query()->sole();
    $container = collect($steps)->first(fn ($step): bool => is_array($step) && ($step['command'][0] ?? null) === 'docker' && $step['command'][1] === 'run');

    expect($run->only(['kind', 'custom', 'status', 'result', 'branch', 'commit', 'pull_request_id', 'agent', 'queue_seconds', 'summary']))->toBe([
        'kind' => 'security', 'custom' => true, 'status' => ReviewRun::STATUS_UNPUBLISHED, 'result' => 'FAILED', 'branch' => 'main',
        'commit' => str_repeat('a', 40), 'pull_request_id' => null, 'agent' => 'claude-code', 'queue_seconds' => 3,
        'summary' => 'A Laravel shop. The public API was read in full.',
    ])
        ->and($run->findings[0]['title'])->toBe('Any signed-in user can download another company\'s invoices')
        ->and((float) $run->cost)->toBe(1.5)
        ->and(RunPresenter::title($run))->toBe('Security review of main')
        ->and(RunPresenter::outcome($run))->toBe(['label' => 'Findings to act on', 'tone' => 'changes'])
        // One prompt, and the guide for the repository's stack beside it.
        ->and(implode(' ', $container['command']))->toContain('sh .review/skill/run-security.sh')
        ->and($container['environment']['REVIEW_COMMIT'])->toBe(str_repeat('a', 40))
        // What the installation set for it reaches the script that reads it.
        ->and($container['environment']['SECURITY_REVIEW_MAX_BUDGET'])->toBe('20')
        ->and($container['environment'])->not->toHaveKey('SECURITY_REVIEW_MODEL')
        ->and($container['environment']['ANTHROPIC_API_KEY'])->toBe('sk-ant-one')
        ->and($steps['guides'])->toBe(['stack-laravel.md'])
        ->and($steps['prompt'])->toBeNull()
        ->and(app(RunLog::class)->tail($run)['text'])->toContain('Security review of acme/shop, branch main', 'Filed: 1 finding(s).')
        // The checkout is gone, and so is the reviewer's work.
        ->and(File::exists(storage_path("framework/testing/review-work/{$this->repository->id}-41")))->toBeFalse();

    // Read from the host to clone, and nothing written to it.
    Http::assertNothingSent();
});

it('records a security review whose reviewer left no report as not finished', function (?array $report, int $exit) {
    ($this->reviewer)($report, $exit);

    app(SecurityReview::class)->run($this->repository, 'main', 41);

    expect(ReviewRun::query()->sole()->only(['kind', 'status']))->toBe(['kind' => 'security', 'status' => ReviewRun::STATUS_FAILED]);
})->with([
    'nothing written' => [null, 0],
    'the reviewer failed' => [['result' => 'PASSED', 'summary' => 'Half done.', 'findings' => []], 1],
    'findings that are no list' => [['result' => 'PASSED', 'summary' => 'Odd.', 'findings' => 'none'], 0],
]);

it('closes the run of a security review whose job died', function () {
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'main', 'commit' => str_repeat('a', 40), 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'kind' => 'security', 'custom' => true, 'started_at' => now(),
    ]);

    (new RunSecurityReview($this->repository->id, 'main', 41))->failed(new RuntimeException('killed'));

    expect(ReviewRun::query()->sole()->status)->toBe(ReviewRun::STATUS_FAILED);
});

it('lists security reviews on their own, and shows one as a report', function () {
    ($this->reviewer)($this->report);
    app(SecurityReview::class)->run($this->repository, 'main', 41);
    $run = ReviewRun::query()->sole();

    $user = User::factory()->create();
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setTenant($this->repository->tenant);

    livewire(ListReviewRuns::class)->set('activeTab', 'security')->assertCanSeeTableRecords([$run])->assertSee('Security review of main');
    // Not among the trials: it was never a pull request's review.
    livewire(ListReviewRuns::class)->set('activeTab', 'trials')->assertCanNotSeeTableRecords([$run]);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->assertOk()
        ->assertSee('Security review of main')
        ->assertSee('Any signed-in user can download another company');
});

it('leaves a security review that could not clone its branch on record, as one that did not finish', function () {
    Process::fake(fn ($process) => ((array) $process->command)[1] === 'clone'
        ? Process::result(errorOutput: 'Remote branch nope not found', exitCode: 128)
        : Process::result());

    rescue(fn () => app(SecurityReview::class)->run($this->repository, 'nope', 41), report: false);

    $run = ReviewRun::query()->sole();

    expect($run->only(['kind', 'status', 'branch']))->toBe(['kind' => 'security', 'status' => ReviewRun::STATUS_FAILED, 'branch' => 'nope'])
        ->and(app(RunLog::class)->tail($run)['text'])->toContain('The security review did not finish');
});

it('says why a tenant without an Anthropic connection gets no security review, and starts no sandbox', function () {
    Connection::query()->where('tenant_id', $this->repository->tenant_id)->where('kind', 'llm')->delete();
    $steps = ($this->reviewer)($this->report);

    app(SecurityReview::class)->run($this->repository, 'main', 41);

    $run = ReviewRun::query()->sole();

    expect($run->status)->toBe(ReviewRun::STATUS_FAILED)
        ->and(app(RunLog::class)->tail($run)['text'])->toContain('This tenant has no Anthropic connection')
        ->and(collect($steps)->contains(fn ($step): bool => is_array($step) && ($step['command'][0] ?? null) === 'docker'))->toBeFalse();
});

it('gives the reviewer the platform\'s own security prompt where it keeps one', function () {
    ReviewInstructions::customiseBase(ReviewInstructions::SECURITY)->update(['body' => 'Our own security prompt.']);
    $steps = ($this->reviewer)($this->report);

    app(SecurityReview::class)->run($this->repository, 'main', 41);

    // Written by the worker beside the stack's guide; the script reads it in place of the one that ships.
    expect($steps['prompt'])->toBe('Our own security prompt.');
});
