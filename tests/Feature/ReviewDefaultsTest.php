<?php

use App\Enums\ConnectionKind;
use App\Enums\ReviewCommandAccess;
use App\Filament\App\Pages\ReviewDefaults;
use App\Filament\App\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Components\ReviewSettingsForm;
use App\Llm\AnthropicDriver;
use App\Models\Connection;
use App\Models\ExchangeRate;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReviewSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'slug' => 'shop']);

    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant, ['role' => 'admin']);
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

it('sets each reviewer\'s model and effort under Review defaults, and says what runs as the form stands', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);
    ReviewSettings::saveDefaults($this->tenant, ['ocr_model' => 'gpt-test']);

    // Nothing listed: the keys decide, and the page says on which.
    livewire(ReviewDefaults::class)
        ->assertSee('Every pull request is reviewed by Claude Code')
        ->fillForm([
            'providers' => [['provider' => 'claude-code', 'share' => 100, 'budget' => '50'], ['provider' => 'codex-cli', 'share' => 0, 'budget' => null]],
            'claude_model' => 'smartest', 'claude_effort' => 'high', 'codex_model' => 'gpt-kept',
        ])
        ->assertSee('Each pull request is reviewed by one of these')
        ->assertSee('model smartest (now claude-opus-5-5), effort High')
        ->assertSee('only when none above can run')
        ->assertSee('no key for it')
        ->call('save')
        ->assertHasNoFormErrors();

    $defaults = ReviewSettings::defaults($this->tenant)->refresh();

    expect($defaults->claude_model)->toBe('smartest')
        ->and($defaults->claude_effort?->value)->toBe('high')
        ->and($defaults->codex_model)->toBe('gpt-kept')
        ->and($defaults->ocr_model)->toBe('gpt-test');
});

it('keeps the AI providers to their keys, and leaves the review defaults alone when one is added or changed', function () {
    ReviewSettings::saveDefaults($this->tenant, ['claude_model' => 'claude-opus-5-5', 'claude_effort' => 'high', 'claude_max_budget' => 'none']);
    $anthropic = Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);

    livewire(ManageAiProviders::class)
        ->callAction('create', ['driver' => 'anthropic', 'name' => 'Anthropic two', 'credentials' => ['api_key' => 'sk-ant-two']])
        ->assertHasNoActionErrors()
        ->callTableAction('edit', $anthropic, ['name' => 'Anthropic renamed'])
        ->assertHasNoTableActionErrors();

    $defaults = ReviewSettings::defaults($this->tenant)->refresh();

    expect($defaults->claude_model)->toBe('claude-opus-5-5')
        ->and($defaults->claude_effort?->value)->toBe('high')
        ->and($defaults->claude_max_budget)->toBe('none')
        ->and($anthropic->refresh()->name)->toBe('Anthropic renamed');

    $this->get('/app/acme/ai-providers')->assertOk()->assertSee('AI providers');
});

it('sets who may start a review by commenting /review, and asks for the accounts when only some may', function () {
    livewire(ReviewDefaults::class)
        ->assertSchemaStateSet(['review_command' => 'anyone'])
        ->fillForm(['review_command' => 'listed', 'review_command_accounts' => []])
        ->call('save')
        ->assertHasFormErrors(['review_command_accounts' => 'required']);

    livewire(ReviewDefaults::class)
        ->fillForm(['review_command' => 'listed', 'review_command_accounts' => [' dana-demo ', '557058:aaaa-bbbb']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->tenant->refresh()->review_command)->toBe(ReviewCommandAccess::Listed)
        ->and($this->tenant->review_command_accounts)->toBe(['dana-demo', '557058:aaaa-bbbb'])
        ->and($this->tenant->allowsReviewCommandFrom(null, 'Dana-Demo'))->toBeTrue()
        ->and($this->tenant->allowsReviewCommandFrom('557058:aaaa-bbbb', 'someone'))->toBeTrue()
        ->and($this->tenant->allowsReviewCommandFrom('557058:cccc', 'someone'))->toBeFalse()
        ->and($this->tenant->allowsReviewCommandFrom(null, null))->toBeFalse();

    livewire(ReviewDefaults::class)->fillForm(['review_command' => 'nobody'])->call('save')->assertHasNoFormErrors();

    expect($this->tenant->refresh()->allowsReviewCommandFrom('557058:aaaa-bbbb', 'dana-demo'))->toBeFalse();
});

it('offers the models it knows to pick from, newest first, and keeps one typed that no list holds', function () {
    foreach (['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5-20261001', 'gpt-6.1-sol', 'anthropic/claude-opus-5-5'] as $model) {
        ModelPrice::query()->create(['model' => $model, 'input' => '0.000005', 'output' => '0.000025', 'source' => 'litellm', 'fetched_at' => now()]);
    }

    $claude = ReviewSettingsForm::claudeModels();

    // The aliases first, then the SDK's smartest, default and cheapest, then the
    // other IDs; no dated snapshot, no provider's prefix, no other provider's.
    expect(array_keys($claude))->toBe(['opus', 'sonnet', 'haiku', 'smartest', 'default', 'cheapest', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5'])
        ->and($claude['smartest'])->toBe('smartest: now claude-opus-5-5')
        ->and($claude['claude-opus-5-5'])->toBe('claude-opus-5-5 (smartest)')
        ->and(array_keys(ReviewSettingsForm::openAiModels()))->toBe(['smartest', 'default', 'cheapest', 'gpt-6-astra', 'gpt-6.1-sol', 'gpt-6-luna']);

    livewire(ReviewDefaults::class)
        ->fillForm(['ocr_model' => 'my-gateway/claude-x'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ReviewSettings::defaults($this->tenant)->ocr_model)->toBe('my-gateway/claude-x');

    livewire(ReviewDefaults::class)
        ->fillForm(['ocr_model' => 'not a model; rm -rf'])
        ->call('save')
        ->assertHasFormErrors(['ocr_model']);
});

it('reads a tier as the model the SDK names for it when the review runs', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id]);
    ReviewSettings::defaults($this->tenant)->forceFill(['claude_model' => 'smartest', 'claude_advisor' => 'cheapest', 'codex_model' => 'default', 'ocr_model' => 'smartest'])->save();

    expect(ReviewSettings::variables($repository))->toMatchArray([
        'CLAUDE_REVIEW_MODEL' => 'claude-opus-5-5',
        'CLAUDE_REVIEW_ADVISOR' => 'claude-haiku-5-5',
        'CODEX_REVIEW_MODEL' => 'gpt-6.1-sol',
        // Not a model setting a tier is read for: as it is.
        'OCR_REVIEW_MODEL' => 'smartest',
    ])
        ->and(ReviewSettings::model('anthropic', 'claude-opus-5'))->toBe('claude-opus-5');
});

it('offers a provider connection its smartest, default and cheapest model, read when it is used', function () {
    $connection = Connection::factory()->create([
        'tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::Llm, 'driver' => 'gemini', 'name' => 'Gemini',
        'credentials' => ['api_key' => 'gemini-key'], 'settings' => ['model' => 'cheapest'], 'webhook_key' => null, 'webhook_secret' => null,
    ]);
    $driver = $connection->driver();
    $cheapest = ReviewSettings::tierModels('gemini')['cheapest'];

    expect($cheapest)->not->toBe('')
        ->and($driver->environment($connection))->toContain($cheapest)
        ->and(array_keys($driver->settingFields()[0]->getOptions()))->toContain('smartest', 'default', 'cheapest', $cheapest);
});

it('offers OpenCodeReview real model names only, which it passes on as they are', function () {
    livewire(ReviewDefaults::class)
        ->assertFormFieldExists('ocr_model', function (Select $field): bool {
            $options = array_keys($field->getOptions());

            return array_intersect($options, ['opus', 'sonnet', 'haiku', 'smartest', 'default', 'cheapest']) === []
                && in_array('claude-opus-5-5', $options, true);
        });
});

it('says what a reviewer left to itself ran on last, and takes the SDK\'s models where the API is asked directly', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);

    livewire(ReviewDefaults::class)->assertSee('model its own default, effort its own default');

    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id]);
    ReviewRun::query()->forceCreate([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'x',
        'commit' => str_repeat('a', 40), 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(), 'agent' => 'claude-code', 'model' => 'claude-opus-5-5', 'effort' => 'xhigh',
    ]);

    livewire(ReviewDefaults::class)->assertSee('model its own default (the last review with it used claude-opus-5-5), effort its own default (the last review with it used xhigh)');

    $tiers = ReviewSettings::tierModels('anthropic');

    expect(AnthropicDriver::defaultModel())->toBe($tiers['smartest'])
        ->and(AnthropicDriver::smallModel())->toBe($tiers['cheapest']);
});
