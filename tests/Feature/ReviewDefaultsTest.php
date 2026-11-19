<?php

use App\Filament\App\Pages\ReviewDefaults;
use App\Filament\App\Resources\AiProviders\Pages\ManageAiProviders;
use App\Models\Connection;
use App\Models\ExchangeRate;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReviewSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'slug' => 'shop']);

    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant);
    $this->actingAs($this->user);

    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    // What a reviewer's reviews cost this month, in dollars.
    $this->spend = function (string $agent, float $cost, ?Tenant $tenant = null, mixed $at = null): ReviewRun {
        static $build = 0;

        $run = ReviewRun::query()->create([
            'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
            'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_pad((string) ++$build, 40, 'a'), 'build_number' => $build,
            'status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED', 'started_at' => $at ?? now(), 'agent' => $agent, 'cost' => $cost,
        ]);

        // Past the panel, which gives everything made in it to the tenant that is open.
        if ($tenant !== null) {
            DB::table('review_runs')->where('id', $run->id)->update(['tenant_id' => $tenant->id]);
        }

        return $run;
    };

    $this->reviewer = fn (): ?string => ReviewSettings::variables($this->repository->fresh(), ['claude-code', 'codex-cli'], '7')['REVIEW_PROVIDER'] ?? null;

    // Claude until its budget is spent, Codex after.
    $this->claudeThenCodex = fn (float $budget = 500) => ReviewSettings::saveDefaults($this->tenant, ['providers' => [
        ['provider' => 'claude-code', 'share' => 100, 'budget' => $budget],
        ['provider' => 'codex-cli', 'share' => 0, 'budget' => null],
    ]]);
});

it('has the review settings on a page of their own, not behind a button on the repositories', function () {
    $this->get('/app/acme/review-settings')->assertOk()->assertSee('For every repository of Acme');
    $this->get('/app/acme/repositories')->assertOk()->assertSee('Review settings')->assertDontSee('Default review settings');
});

it('saves the reviewers with their share and monthly budget', function () {
    livewire(ReviewDefaults::class)
        ->fillForm([
            'providers' => [
                ['provider' => 'claude-code', 'share' => 100, 'budget' => '500'],
                ['provider' => 'codex-cli', 'share' => 0, 'budget' => null],
            ],
            'ocr_model' => 'gpt-test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $defaults = ReviewSettings::defaults($this->tenant);

    expect($defaults->ocr_model)->toBe('gpt-test')
        ->and(array_values($defaults->providers))->toEqual([
            ['provider' => 'claude-code', 'share' => 100, 'budget' => 500],
            ['provider' => 'codex-cli', 'share' => 0, 'budget' => null],
        ]);

    // And shows them again.
    livewire(ReviewDefaults::class)->assertSchemaStateSet(['ocr_model' => 'gpt-test']);
});

it('uses the first reviewer until its month has cost its budget, and the next after', function () {
    ($this->claudeThenCodex)();

    ($this->spend)('claude-code', 499.99);
    // Not this month, not this reviewer, not this tenant.
    ($this->spend)('claude-code', 300, at: now()->subMonth()->startOfMonth());
    ($this->spend)('codex-cli', 300);
    ($this->spend)('claude-code', 300, Tenant::factory()->create());

    expect(($this->reviewer)())->toBe('claude-code');

    ($this->spend)('claude-code', 0.01);

    expect(($this->reviewer)())->toBe('codex-cli');
});

it('compares the budget in the tenant\'s currency', function () {
    ExchangeRate::query()->create(['currency' => 'EUR', 'rate' => 0.8, 'fetched_at' => now()]);
    $this->tenant->update(['currency' => 'EUR']);
    ($this->claudeThenCodex)();

    // $600 is €480: still within €500.
    ($this->spend)('claude-code', 600);

    expect(($this->reviewer)())->toBe('claude-code');

    ($this->spend)('claude-code', 30);

    expect(($this->reviewer)())->toBe('codex-cli');
});

it('still reviews when every reviewer is over its budget', function () {
    ReviewSettings::saveDefaults($this->tenant, ['providers' => [
        ['provider' => 'claude-code', 'share' => 100, 'budget' => 10],
        ['provider' => 'codex-cli', 'share' => 0, 'budget' => 10],
    ]]);

    ($this->spend)('claude-code', 50);
    ($this->spend)('codex-cli', 50);

    expect(($this->reviewer)())->toBe('claude-code');
});

it('leaves a reviewer without a budget alone, whatever it cost', function () {
    ReviewSettings::saveDefaults($this->tenant, ['providers' => [['provider' => 'claude-code', 'share' => 100], ['provider' => 'codex-cli', 'share' => 0]]]);

    ($this->spend)('claude-code', 5000);

    expect(($this->reviewer)())->toBe('claude-code');
});

it('sets the model and effort a reviewer runs at with the AI provider it runs on, and leaves the rest of the defaults alone', function () {
    ReviewSettings::saveDefaults($this->tenant, ['ocr_model' => 'gpt-test', 'codex_model' => 'gpt-kept']);
    $anthropic = Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);

    livewire(ManageAiProviders::class)
        ->assertCanSeeTableRecords([$anthropic])
        ->mountTableAction('edit', $anthropic)
        ->assertTableActionDataSet(['defaults.claude_model' => null, 'defaults.claude_effort' => null])
        ->setTableActionData(['defaults' => ['claude_model' => 'claude-opus-5-5', 'claude_effort' => 'high']])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $defaults = ReviewSettings::defaults($this->tenant)->refresh();

    expect($defaults->claude_model)->toBe('claude-opus-5-5')
        ->and($defaults->claude_effort?->value)->toBe('high')
        ->and($defaults->codex_model)->toBe('gpt-kept')
        ->and($defaults->ocr_model)->toBe('gpt-test')
        ->and($anthropic->refresh()->credential('api_key') ?? $anthropic->credentials)->not->toBeEmpty();

    $this->get('/app/acme/ai-providers')->assertOk()->assertSee('AI providers');
    $this->get('/app/acme/source-control')->assertOk();
});

it('keeps the reviewer\'s settings when another key for its provider is added with the fields left empty', function () {
    ReviewSettings::saveDefaults($this->tenant, ['claude_model' => 'claude-opus-5-5', 'claude_max_budget' => 'none']);

    livewire(ManageAiProviders::class)
        ->callAction('create', ['driver' => 'anthropic', 'name' => 'Anthropic two', 'credentials' => ['api_key' => 'sk-ant-two'], 'defaults' => ['claude_effort' => 'high']])
        ->assertHasNoActionErrors();

    $defaults = ReviewSettings::defaults($this->tenant)->refresh();

    expect($defaults->claude_model)->toBe('claude-opus-5-5')
        ->and($defaults->claude_max_budget)->toBe('none')
        // What was filled in is taken.
        ->and($defaults->claude_effort?->value)->toBe('high');
});
