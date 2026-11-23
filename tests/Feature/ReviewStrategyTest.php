<?php

use App\Enums\ReviewComplexity;
use App\Enums\ReviewEffort;
use App\Filament\App\Pages\ReviewDefaults;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Llm\Agents\Classifier;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewClassification;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runners\Container\Precheck;
use App\Runners\Container\RunLog;
use App\Services\PullRequestClassifier;
use App\Services\ReviewSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

it('starts a simple pull request at the minimum, a normal one a step up and a complex one two, up to the top', function (string $effort, string $tier, ReviewComplexity $complexity, string $wantEffort, string $wantTier) {
    $pick = ReviewSettings::pick(ReviewEffort::from($effort), $tier, $complexity);

    expect($pick['effort']->value)->toBe($wantEffort)->and($pick['tier'])->toBe($wantTier);
})->with([
    ['low', 'cheapest', ReviewComplexity::Simple, 'low', 'cheapest'],
    ['low', 'cheapest', ReviewComplexity::Complex, 'high', 'smartest'],
    ['medium', 'default', ReviewComplexity::Simple, 'medium', 'default'],
    ['medium', 'default', ReviewComplexity::Normal, 'high', 'smartest'],
    ['medium', 'default', ReviewComplexity::Complex, 'xhigh', 'smartest'],
    ['high', 'smartest', ReviewComplexity::Complex, 'xhigh', 'smartest'],
]);

it('reads the files a diff changes, their size, and the diff of its migrations', function () {
    $files = PullRequestClassifier::files(implode("\n", [
        'diff --git a/app/Export.php b/app/Export.php',
        '--- a/app/Export.php',
        '+++ b/app/Export.php',
        '@@ -1,2 +1,3 @@',
        '-old',
        '+new',
        '+more',
        'diff --git a/database/migrations/2026_01_01_add.php b/database/migrations/2026_01_01_add.php',
        '+++ b/database/migrations/2026_01_01_add.php',
        '+Schema::dropColumn(\'email\');',
    ]));

    expect($files['files'])->toBe(['app/Export.php' => ['added' => 2, 'removed' => 1], 'database/migrations/2026_01_01_add.php' => ['added' => 1, 'removed' => 0]])
        ->and($files['added'])->toBe(3)
        ->and($files['migrations'])->toContain("dropColumn('email')")->not->toContain('app/Export.php');
});

describe('in the precheck', function () {
    beforeEach(function () {
        $this->sha = str_repeat('a', 40);
        $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_stack' => ['laravel']]);
        $this->work = storage_path('framework/testing/strategy');
        File::deleteDirectory($this->work);
        File::ensureDirectoryExists($this->work);
        ReviewInstruction::query()->create(['key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => 'Review carefully.']);
        Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

        $pullRequest = [
            'id' => 570, 'title' => 'Add the export', 'description' => 'Adds the export.', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
            'destination' => ['branch' => ['name' => 'main']],
        ];

        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests?*' => Http::response(['values' => [$pullRequest]]),
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => []]),
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response("diff --git a/app/Auth.php b/app/Auth.php\n+++ b/app/Auth.php\n+check\n"),
            'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response($pullRequest),
        ]);

        $this->precheck = fn (): array => app(Precheck::class)->run($this->repository, 'feature/export', $this->sha, 41, $this->work, null, app(RunLog::class)->writer($this->repository->id, 41));
        $this->variables = fn (): array => (array) File::json("{$this->work}/review-context.json")['settings']['server_variables'];
        $this->log = fn (): string => File::get(app(RunLog::class)->path($this->repository->id, 41));
    });

    afterEach(fn () => File::deleteDirectory($this->work));

    it('runs a pull request classified as complex two steps above the minimum, and classifies a commit once', function () {
        Classifier::fake([['complexity' => 'complex', 'kind' => 'feature', 'database' => false, 'breaking' => false, 'security' => true, 'irreversible' => false, 'reason' => 'It changes the login check.']]);
        ReviewSettings::saveDefaults($this->repository->tenant, ['strategy' => 'apply', 'claude_effort' => 'low', 'claude_model' => 'claude-haiku-5-5']);

        ($this->precheck)();

        expect(($this->variables)())->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'xhigh', 'CLAUDE_REVIEW_MODEL' => ReviewSettings::tierModels('anthropic')['smartest']])
            ->and(($this->log)())->toContain('Classified as Complex · feature · security: It changes the login check. Runs at effort xhigh and the smartest model.');

        $classification = ReviewClassification::query()->sole();
        expect($classification->commit)->toBe($this->sha)
            ->and($classification->files_count)->toBe(1)
            ->and($classification->flags)->toBe(['security']);

        ($this->precheck)();

        expect(ReviewClassification::query()->count())->toBe(1);
        Classifier::assertPrompted(fn (): bool => true);
    });

    it('only says what it would pick when recording, and runs at what is set', function () {
        Classifier::fake([['complexity' => 'simple', 'kind' => 'content', 'database' => false, 'breaking' => false, 'security' => false, 'irreversible' => false, 'reason' => 'Copy only.']]);
        ReviewSettings::saveDefaults($this->repository->tenant, ['strategy' => 'record', 'claude_effort' => 'high', 'claude_model' => 'claude-opus-5-5']);

        ($this->precheck)();

        expect(($this->variables)())->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'high', 'CLAUDE_REVIEW_MODEL' => 'claude-opus-5-5'])
            ->and(($this->log)())->toContain('Would run at effort medium and the default model; recorded only');
    });

    it('reviews at what is set when the classification fails', function () {
        Http::fake(['api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response('', 500)]);
        ReviewSettings::saveDefaults($this->repository->tenant, ['strategy' => 'apply', 'claude_effort' => 'high']);

        expect(($this->precheck)()['REVIEW_NEEDED'])->toBe('true')
            ->and(($this->variables)())->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'high'])
            ->and(($this->log)())->toContain('Not classified (')
            ->and(ReviewClassification::query()->count())->toBe(0);
    });

    it('lets a repository turn it on for itself, from its own minimum', function () {
        Classifier::fake([['complexity' => 'normal', 'kind' => 'fix', 'database' => false, 'breaking' => false, 'security' => false, 'irreversible' => false, 'reason' => 'A contained fix.']]);
        ReviewSettings::saveFor($this->repository, ['strategy' => 'apply', 'strategy_effort' => 'high', 'strategy_model' => 'smartest']);

        ($this->precheck)();

        expect(($this->variables)())->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'xhigh', 'CLAUDE_REVIEW_MODEL' => ReviewSettings::tierModels('anthropic')['smartest']]);
    });
});

it('says on Review defaults what each complexity runs at', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'admin']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    livewire(ReviewDefaults::class)
        ->fillForm(['strategy' => 'apply', 'strategy_effort' => 'medium', 'strategy_model' => 'default'])
        ->assertSee('simple: Medium, default; normal: High, smartest; complex: Extra high, smartest')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ReviewSettings::defaults($tenant)->strategy)->toBe('apply');
});

it('shows what a commit was classified as with its run', function () {
    $repository = Repository::factory()->create(['slug' => 'shop']);
    $user = User::factory()->create();
    $user->tenants()->attach($repository->tenant, ['role' => 'admin']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($repository->tenant);
    Filament::bootCurrentPanel();

    $run = ReviewRun::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);
    ReviewClassification::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'pull_request_id' => 570, 'commit' => str_repeat('a', 40),
        'complexity' => 'complex', 'kind' => 'feature', 'flags' => ['security'], 'reason' => 'It changes the login check.', 'files_count' => 3, 'lines_added' => 40, 'lines_removed' => 2,
    ]);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->assertSee('Complex · feature · security')
        ->assertSee('It changes the login check.');
});

it('gives Pi the picked effort too, which has no effort setting of its own', function () {
    $repository = Repository::factory()->create();

    expect(ReviewSettings::variables($repository, null, '570', null, ['effort' => ReviewEffort::Xhigh, 'tier' => 'smartest']))->toMatchArray(['PI_REVIEW_EFFORT' => 'xhigh', 'CLAUDE_REVIEW_EFFORT' => 'xhigh', 'OCR_REVIEW_EFFORT' => 'xhigh'])
        ->and(ReviewSettings::variables($repository, null, '570'))->not->toHaveKey('PI_REVIEW_EFFORT');
});

it('steps only the effort up when the model is each reviewer\'s own, and leaves the models as set', function () {
    $repository = Repository::factory()->create();
    ReviewSettings::saveDefaults($repository->tenant, ['claude_model' => 'claude-sonnet-test', 'strategy' => 'apply', 'strategy_effort' => 'low', 'strategy_model' => ReviewSettings::OWN_MODEL]);

    $strategy = ReviewSettings::strategy($repository);
    $pick = ReviewSettings::pick($strategy['effort'], $strategy['tier'], ReviewComplexity::Complex);

    expect($pick)->toBe(['effort' => ReviewEffort::High, 'tier' => null])
        ->and(ReviewSettings::variables($repository, null, '570', null, $pick))->toMatchArray(['CLAUDE_REVIEW_EFFORT' => 'high', 'CLAUDE_REVIEW_MODEL' => 'claude-sonnet-test']);
});
