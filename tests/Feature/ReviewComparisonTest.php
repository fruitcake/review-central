<?php

use App\Exceptions\ReviewTrialRefused;
use App\Filament\App\Resources\ReviewComparisons\Pages\ListReviewComparisons;
use App\Filament\App\Resources\ReviewComparisons\Pages\ViewReviewComparison;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Jobs\AnalyzeReviewComparison;
use App\Llm\Agents\Comparer;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewComparison;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReviewComparisons;
use App\Services\ReviewSettings;
use App\Services\ReviewTrial;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->repository->tenant, ['role' => 'admin']);
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    $this->run = fn (?int $build, array $findings, string $status = ReviewRun::STATUS_FINISHED, array $attributes = []): ReviewRun => ReviewRun::query()->create($attributes + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => $status, 'started_at' => now(),
        'build_number' => $build, 'agent' => 'claude-code', 'model' => 'claude-opus-5-5', 'effort' => 'high', 'findings' => $findings,
    ]);

    $this->base = ($this->run)(41, [['id' => 'F1', 'severity' => 'MEDIUM', 'title' => 'Export leaks invoices', 'path' => 'app/Export.php', 'line' => 12]]);
});

it('starts a trial for each setup from a review, and shows them side by side with it', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Connection::factory()->openai()->create(['tenant_id' => $this->repository->tenant_id]);
    $this->mock(ReviewTrial::class)->shouldReceive('begin')
        ->twice()
        ->andReturn(['repository' => $this->repository, 'build' => 51], ['repository' => $this->repository, 'build' => 52]);

    livewire(ViewReviewRun::class, ['record' => $this->base->uuid])
        ->callAction('compare', [
            'ignore_existing' => true,
            'rows' => [
                ['provider' => 'codex-cli', 'model' => 'gpt-test', 'effort' => 'xhigh'],
                ['provider' => 'claude-code', 'effort' => 'low'],
            ],
        ])
        ->assertHasNoActionErrors();

    $comparison = ReviewComparison::query()->sole();

    expect($comparison->review_run_id)->toBe($this->base->id)
        ->and(array_column($comparison->rows, 'build'))->toBe([51, 52])
        ->and($comparison->rows[0])->toMatchArray(['provider' => 'codex-cli', 'model' => 'gpt-test', 'effort' => 'xhigh', 'ignore_existing' => true]);

    ($this->run)(51, [['id' => 'F1', 'severity' => 'LOW', 'title' => 'Export is slow']], attributes: ['agent' => 'codex-cli', 'model' => 'gpt-test', 'effort' => 'xhigh']);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertSee('A · started from')
        ->assertSee('Export leaks invoices')
        ->assertSee('codex-cli · gpt-test · effort xhigh')
        ->assertSee('Export is slow')
        // C has not started yet, so it cannot be analysed.
        ->assertSee('Its review has not started yet.')
        ->assertActionDisabled('analyze');
});

it('keeps why a setup could not be tried, and starts the others', function () {
    $this->mock(ReviewTrial::class)->shouldReceive('begin')
        ->twice()
        ->andReturnUsing(fn (Tenant $tenant, string $url, array $options): array => $options['runner'] === 'diff'
            ? throw new ReviewTrialRefused('Reviewing from the diff alone is turned off on this installation.')
            : ['repository' => $this->repository, 'build' => 52]);

    $comparison = app(ReviewComparisons::class)->start($this->base, [
        ['runner' => 'diff', 'provider' => null, 'model' => null, 'effort' => null, 'verify' => false],
        ['runner' => null, 'provider' => 'codex-cli', 'model' => null, 'effort' => null, 'verify' => false],
    ], true, $this->user);

    expect($comparison->rows[0]['error'])->toBe('Reviewing from the diff alone is turned off on this installation.')
        ->and($comparison->rows[1]['build'])->toBe(52)
        ->and($comparison->done())->toBeFalse();

    ($this->run)(52, []);

    expect($comparison->refresh()->done())->toBeTrue();
});

it('has one model judge the findings together, and scores each setup', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response("diff --git a/app/Export.php b/app/Export.php\n+return Invoice::all();\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response(['id' => 570, 'title' => 'Add the export', 'description' => 'Adds it.']),
    ]);
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ($this->run)(51, [['id' => 'F1', 'severity' => 'MEDIUM', 'title' => 'All invoices are exported'], ['id' => 'F2', 'severity' => 'LOW', 'title' => 'No rate limit']]);
    ($this->run)(52, [['id' => 'F1', 'severity' => 'HIGH', 'title' => 'SQL injection']]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51], ['build' => 52]],
    ]);

    Comparer::fake([[
        'summary' => 'B found what A found, and more.',
        'findings' => [
            ['title' => 'Every company\'s invoices are exported', 'path' => 'app/Export.php', 'verdict' => 'valid', 'found_by' => ['A-F1', 'B-F1'], 'reason' => 'Invoice::all() has no tenant scope.'],
            ['title' => 'SQL injection', 'path' => null, 'verdict' => 'false_positive', 'found_by' => ['C-F1', 'Z-F9'], 'reason' => 'No raw SQL in the diff.'],
            ['title' => 'No rate limit', 'path' => null, 'verdict' => 'valid', 'found_by' => ['B-F2'], 'reason' => 'The export is public.'],
        ],
    ]]);

    AnalyzeReviewComparison::dispatchSync($comparison->id, $connection->id, 'smartest');
    $comparison->refresh();

    expect($comparison->analysis_status)->toBe(ReviewComparison::ANALYSIS_FINISHED)
        // An id no review has is left out.
        ->and($comparison->analysis['findings'][1]['found_by'])->toBe(['C-F1'])
        ->and(ReviewComparisons::scores($comparison))->toBe([
            'A' => ['valid' => 1, 'false_positive' => 0, 'unclear' => 0, 'missed' => 1],
            'B' => ['valid' => 2, 'false_positive' => 0, 'unclear' => 0, 'missed' => 0],
            'C' => ['valid' => 0, 'false_positive' => 1, 'unclear' => 0, 'missed' => 2],
        ]);

    Comparer::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'A-F1 · MEDIUM · Export leaks invoices')
        && str_contains($prompt->prompt, '+return Invoice::all();')
        && str_contains($prompt->prompt, 'Review C: claude-code'));

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertSee('B found what A found, and more.')
        ->assertSee('False positive')
        ->assertActionEnabled('analyze');
});

it('queues the analysis from the page once every review has ended', function () {
    Queue::fake();
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->callAction('analyze', ['connection' => $connection->id, 'model' => 'default'])
        ->assertHasNoActionErrors();

    expect($comparison->refresh()->analysis_status)->toBe(ReviewComparison::ANALYSIS_RUNNING);
    Queue::assertPushed(AnalyzeReviewComparison::class, fn (AnalyzeReviewComparison $job): bool => $job->connectionId === $connection->id && $job->model === 'default');
});

it('is for the tenant\'s admins only', function () {
    $member = User::factory()->create();
    $member->tenants()->attach($this->repository->tenant, ['role' => 'member']);
    $this->actingAs($member);

    livewire(ViewReviewRun::class, ['record' => $this->base->uuid])->assertActionHidden('compare');
    livewire(ListReviewComparisons::class)->assertForbidden();
});

it('finds a trial\'s run under the repository it ran in, which need not be the review\'s', function () {
    $other = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'slug' => 'shop']);
    ($this->run)(51, [['id' => 'F1', 'title' => 'In this repository']]);
    ($this->run)(51, [['id' => 'F1', 'title' => 'In the other one']], attributes: ['repository_id' => $other->id]);

    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51, 'repository_id' => $other->id]],
    ]);

    expect($comparison->columns()['B']['run']->findings[0]['title'])->toBe('In the other one');
});

it('will not judge reviews against a diff none of them read, and says which read an older one', function () {
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $head = str_repeat('c', 12);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response("diff --git a/app/Export.php b/app/Export.php\n+return Invoice::all();\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => function () use (&$head) {
            return Http::response(['id' => 570, 'title' => 'Add the export', 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $head]]]);
        },
    ]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51]],
    ]);
    ($this->run)(51, [], attributes: ['commit' => str_repeat('b', 40)]);

    // Moved on past both.
    AnalyzeReviewComparison::dispatchSync($comparison->id, $connection->id);

    expect($comparison->refresh()->analysis_status)->toBe(ReviewComparison::ANALYSIS_FAILED)
        ->and($comparison->analysis_error)->toContain('The pull request has moved on to cccccccccccc');

    // At B's commit: A is said to be of an older one.
    $head = str_repeat('b', 12);
    Comparer::fake([['summary' => 'Fine.', 'findings' => []]]);
    AnalyzeReviewComparison::dispatchSync($comparison->id, $connection->id);

    expect($comparison->refresh()->analysis_status)->toBe(ReviewComparison::ANALYSIS_FINISHED);
    Comparer::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, "## Review A: claude-code · claude-opus-5-5 · effort high\nOf an older commit, aaaaaaaaaaaa")
        && ! str_contains($prompt->prompt, "Review B: claude-code · claude-opus-5-5 · effort high\nOf an older commit"));
});

it('runs the analysis on the reviews\' queue, and leaves it to try again when it cannot finish', function () {
    config(['review.container.queue' => 'reviews']);
    Queue::fake();
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [], 'analysis_status' => ReviewComparison::ANALYSIS_RUNNING,
    ]);

    AnalyzeReviewComparison::dispatch($comparison->id, $connection->id);
    Queue::assertPushedOn('reviews', AnalyzeReviewComparison::class);

    // Its AI provider removed while it waited.
    $connection->delete();
    (new AnalyzeReviewComparison($comparison->id, $connection->id))->handle(app(ReviewComparisons::class));

    expect($comparison->refresh()->analysis_status)->toBe(ReviewComparison::ANALYSIS_FAILED)
        ->and($comparison->analysis_error)->toContain('is gone');

    // A job that died.
    $comparison->update(['analysis_status' => ReviewComparison::ANALYSIS_RUNNING]);
    (new AnalyzeReviewComparison($comparison->id, $connection->id))->failed(new RuntimeException('Timed out'));

    expect($comparison->refresh()->analysis_status)->toBe(ReviewComparison::ANALYSIS_FAILED)
        ->and($comparison->analysis_error)->toContain('Timed out');

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])->assertActionEnabled('analyze');
});

it('picks a trial\'s model from the reviewer\'s list, and gives the reviewer the model a tier is now', function () {
    $anthropic = ReviewSettings::tierModels('anthropic');

    expect(ReviewRunResource::trialModels('claude-code'))->toHaveKeys(['opus', 'smartest', $anthropic['smartest']])
        ->and(ReviewRunResource::trialModels('codex-cli'))->toHaveKey('smartest')->not->toHaveKey('opus')
        // A tier is read for one provider, so not for another reviewer or none.
        ->and(ReviewRunResource::trialModels(null))->not->toHaveKeys(['smartest', 'opus'])
        ->and(ReviewRunResource::trialOptions(['provider' => 'claude-code', 'model' => 'smartest'])['model'])->toBe($anthropic['smartest'])
        ->and(ReviewRunResource::trialOptions(['provider' => 'codex-cli', 'model' => 'default'])['model'])->toBe(ReviewSettings::tierModels('openai')['default'])
        ->and(ReviewRunResource::trialOptions(['provider' => 'pi', 'model' => 'google/gemini-3-pro'])['model'])->toBe('google/gemini-3-pro');
});

it('takes more setups later, and keeps an earlier analysis to the reviews it read', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ($this->run)(51, [['id' => 'F1', 'title' => 'All invoices are exported']]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51]],
        'analysis_status' => ReviewComparison::ANALYSIS_FINISHED,
        'analysis' => ['summary' => 'B did well.', 'labels' => ['A', 'B'], 'findings' => [
            ['title' => 'Leak', 'path' => null, 'verdict' => 'valid', 'found_by' => ['A-F1', 'B-F1'], 'reason' => 'It leaks.'],
        ]],
    ]);
    $this->mock(ReviewTrial::class)->shouldReceive('begin')->once()->andReturn(['repository' => $this->repository, 'build' => 52]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->callAction('add', ['ignore_existing' => true, 'rows' => [['provider' => 'claude-code', 'effort' => 'low']]])
        ->assertHasNoActionErrors()
        ->assertNotified('One setup added');

    expect(array_column($comparison->refresh()->rows, 'build'))->toBe([51, 52]);

    ($this->run)(52, []);

    // C came after the analysis, so it has no scores from it; no "missed" it never had a chance at.
    expect(array_keys(ReviewComparisons::scores($comparison)))->toBe(['A', 'B']);
});

it('keeps a member off a comparison\'s page', function () {
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);
    $member = User::factory()->create();
    $member->tenants()->attach($this->repository->tenant, ['role' => 'member']);
    $this->actingAs($member);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])->assertForbidden();
});

it('leaves a column out, at the end and out of the analysis, and brings it back', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response("diff --git a/app/Export.php b/app/Export.php\n+x\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response(['id' => 570, 'title' => 'Add the export']),
    ]);
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ($this->run)(51, [['id' => 'F1', 'title' => 'Noise from B']]);
    // C still runs, but is left out, so the others can be analysed already.
    ($this->run)(52, [], ReviewRun::STATUS_RUNNING);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51], ['build' => 52]],
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertActionDisabled('analyze')
        ->call('toggleColumn', 'B')
        // Left out, B moves behind C.
        ->assertSeeInOrder(['rc-col-A', 'rc-col-C', 'rc-col-B', 'Bring back'])
        ->call('toggleColumn', 'C')
        ->assertActionEnabled('analyze');

    expect($comparison->refresh()->excluded)->toBe(['B', 'C']);

    Comparer::fake([['summary' => 'Only A.', 'findings' => []]]);
    AnalyzeReviewComparison::dispatchSync($comparison->id, $connection->id);

    Comparer::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Review A:') && ! str_contains($prompt->prompt, 'Noise from B'));
    expect(array_keys(ReviewComparisons::scores($comparison->refresh())))->toBe(['A']);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])->call('toggleColumn', 'B');

    expect($comparison->refresh()->excluded)->toBe(['C']);
});

it('clears a tier or alias when the reviewer or runner changes, and keeps a model by name', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Connection::factory()->openai()->create(['tenant_id' => $this->repository->tenant_id]);

    livewire(ListReviewRuns::class)
        ->mountAction('try')
        ->fillForm(['provider' => 'claude-code', 'model' => 'opus'])
        // Codex has no Claude alias.
        ->fillForm(['provider' => 'codex-cli'])
        ->assertSchemaStateSet(['model' => null])
        // A tier names another model for Codex than for Claude Code.
        ->fillForm(['provider' => 'claude-code', 'model' => 'smartest'])
        ->fillForm(['provider' => 'codex-cli'])
        ->assertSchemaStateSet(['model' => null])
        // The diff alone takes models by name only.
        ->fillForm(['model' => 'default'])
        ->fillForm(['runner' => 'diff'])
        ->assertSchemaStateSet(['model' => null])
        ->fillForm(['model' => 'claude-opus-5-5', 'runner' => null])
        ->assertSchemaStateSet(['model' => 'claude-opus-5-5']);
});

it('adds setups under a lock, keeping what another addition added meanwhile and an old analysis to what it read', function () {
    ($this->run)(51, []);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51]],
        // From before an analysis kept the reviews it read.
        'analysis_status' => ReviewComparison::ANALYSIS_FINISHED, 'analysis' => ['summary' => 'Old.', 'findings' => []],
    ]);
    $stale = ReviewComparison::query()->find($comparison->id);
    // Another addition, in between.
    ReviewComparison::query()->whereKey($comparison->id)->update(['rows' => json_encode([['build' => 51], ['build' => 52]])]);
    $this->mock(ReviewTrial::class)->shouldReceive('begin')->once()->andReturn(['repository' => $this->repository, 'build' => 53]);

    expect(app(ReviewComparisons::class)->add($stale, [['runner' => null, 'provider' => 'claude-code', 'model' => null, 'effort' => null, 'verify' => false]], true))->toBe(1);

    $comparison->refresh();
    expect(array_column($comparison->rows, 'build'))->toBe([51, 52, 53])
        ->and($comparison->analysis['labels'])->toBe(['A', 'B']);
});

it('reads the reviews that could be read when the analysis was asked for, not a setup added while it waited', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response("diff --git a/app/Export.php b/app/Export.php\n+x\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response(['id' => 570, 'title' => 'Add the export']),
    ]);
    Queue::fake();
    $connection = Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])->callAction('analyze', ['connection' => $connection->id]);

    $job = null;
    Queue::assertPushed(AnalyzeReviewComparison::class, function (AnalyzeReviewComparison $pushed) use (&$job): bool {
        $job = $pushed;

        return $pushed->labels === ['A'];
    });

    // B is added and ends before the worker gets to it.
    $comparison->update(['rows' => [['build' => 51]]]);
    ($this->run)(51, [['id' => 'F1', 'title' => 'Late from B']]);
    Comparer::fake([['summary' => 'A alone.', 'findings' => []]]);
    $job->handle(app(ReviewComparisons::class));

    Comparer::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, 'Late from B'));
    expect($comparison->refresh()->analysis['labels'])->toBe(['A'])
        ->and(array_keys(ReviewComparisons::scores($comparison)))->toBe(['A']);
});

it('keeps looking while a left-out review still runs, keeps the scores an analysis gave, and says what it read', function () {
    ($this->run)(51, [['id' => 'F1', 'title' => 'Found by B alone']]);
    ($this->run)(52, [], ReviewRun::STATUS_RUNNING);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51], ['build' => 52]], 'excluded' => ['C'],
        'analysis_status' => ReviewComparison::ANALYSIS_FINISHED,
        'analysis' => ['summary' => 'B found it.', 'labels' => ['A', 'B'], 'findings' => [
            ['title' => 'Leak', 'path' => null, 'verdict' => 'valid', 'found_by' => ['B-F1'], 'reason' => 'It leaks.'],
        ]],
    ]);

    // B is left out after the analysis: its scores stay, and the page says the selection moved on.
    $comparison->toggleExcluded('B');

    expect(ReviewComparisons::scores($comparison))->toBe([
        'A' => ['valid' => 0, 'false_positive' => 0, 'unclear' => 0, 'missed' => 1],
        'B' => ['valid' => 1, 'false_positive' => 0, 'unclear' => 0, 'missed' => 0],
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertSeeHtml('wire:poll.15s')
        ->assertSee('The scores are of A, B.')
        ->assertSee('The reviews to analyse are A now');
});

it('applies two changes to which columns are left out that overlap', function () {
    ($this->run)(51, []);
    ($this->run)(52, []);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51], ['build' => 52]],
    ]);
    $stale = ReviewComparison::query()->find($comparison->id);

    $comparison->toggleExcluded('B');
    $stale->toggleExcluded('C');

    expect($comparison->refresh()->excluded)->toBe(['B', 'C']);
});

it('saves a setup\'s place before its trial starts, so its job never waits on a transaction', function () {
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);
    $level = DB::transactionLevel();
    $this->mock(ReviewTrial::class)->shouldReceive('begin')->once()->andReturnUsing(function () use ($comparison, $level): array {
        // Outside any transaction of its own, with its place already saved.
        expect(DB::transactionLevel())->toBe($level)
            ->and(ReviewComparison::query()->find($comparison->id)->rows)->toHaveCount(1);

        return ['repository' => $this->repository, 'build' => 53];
    });

    app(ReviewComparisons::class)->add($comparison, [['runner' => null, 'provider' => 'claude-code', 'model' => null, 'effort' => null, 'verify' => false]], true);

    expect($comparison->rows[0]['build'])->toBe(53);
});

it('says what the scores are of after a later attempt failed, and for an analysis from before labels were kept', function () {
    ($this->run)(51, [['id' => 'F1', 'title' => 'From B']]);
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [['build' => 51]], 'excluded' => ['B'],
        // An old analysis, and a retry that failed since.
        'analysis' => ['summary' => 'Old.', 'findings' => []],
        'analysis_status' => ReviewComparison::ANALYSIS_FAILED, 'analysis_error' => 'Timed out',
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertSee('The scores are of A, B.')
        ->assertSee('The reviews to analyse are A now')
        ->assertSee('It failed: Timed out');
});

it('shows no scores before an analysis', function () {
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);

    livewire(ViewReviewComparison::class, ['record' => $comparison->uuid])
        ->assertSee('A · started from')
        ->assertDontSee('False positives');
});

it('says a setup could not be started when starting it fails, rather than leave it waiting', function () {
    $comparison = ReviewComparison::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'review_run_id' => $this->base->id, 'rows' => [],
    ]);
    $this->mock(ReviewTrial::class)->shouldReceive('begin')->once()->andThrow(new RuntimeException('The queue is down'));

    app(ReviewComparisons::class)->add($comparison, [['runner' => null, 'provider' => 'claude-code', 'model' => null, 'effort' => null, 'verify' => false]], true);

    expect($comparison->rows[0]['error'])->toBe('It could not be started: The queue is down')
        ->and($comparison->done())->toBeTrue();
});
