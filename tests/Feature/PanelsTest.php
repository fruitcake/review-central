<?php

use App\Enums\ConnectionKind;
use App\Enums\ReviewProvider;
use App\Enums\TicketStyle;
use App\Filament\Admin\Resources\ReviewInstructions\Pages\EditReviewInstruction as EditDefaultInstruction;
use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\App\Resources\Repositories\Pages\ManageRepositories;
use App\Filament\App\Resources\ReviewInstructions\Pages\CreateReviewInstruction as CreateTenantInstruction;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\SourceControl\Pages\ManageSourceControl;
use App\Filament\App\Resources\Tickets\Pages\ManageTickets;
use App\Models\Connection;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\TicketSource;
use App\Models\User;
use App\Runners\ContainerRunner;
use App\Services\RepositoryReadiness;
use App\Services\ReviewInstructions;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    // Another tenant's repository, made before the panel scopes anything to a tenant.
    $this->foreign = Repository::factory()->create();

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant, ['role' => 'admin']);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    // Adding a repository reads it from its host; no test may reach a real one.
    Http::preventStrayRequests();
});

it('opens a tenant only for its own users and for an administrator', function () {
    $other = Tenant::factory()->create(['slug' => 'other']);

    $this->get('/app/acme')->assertOk();
    $this->get('/app/other')->assertNotFound();
    $this->get('/admin')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/app/other')->assertOk();
    $this->get('/admin')->assertOk();
});

it('renders the tenant\'s pages', function (string $path) {
    $this->get("/app/acme/{$path}")->assertOk();
})->with(['source-control', 'ai-providers', 'repositories', 'tickets', 'review-runs', 'review-instructions']);

it('creates a connection the way the form is filled in: the service first, its fields after', function () {
    livewire(ManageSourceControl::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.kind', ConnectionKind::SourceControl->value)
        ->set('mountedActions.0.data.driver', 'bitbucket')
        // The browser can only write into fields that have state: a section left
        // at null swallows what is typed into it.
        ->assertSet('mountedActions.0.data.credentials', ['access_token' => null])
        ->assertSet('mountedActions.0.data.settings', ['workspace' => null, 'username' => null, 'posting' => null, 'add_repositories' => false, 'review_added_repositories' => false, 'clone_in_sandbox' => false])
        ->set('mountedActions.0.data.credentials.access_token', 'secret-token')
        ->set('mountedActions.0.data.settings.workspace', 'acme')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $connection = Connection::query()->sole();

    expect($connection->name)->toBe('Bitbucket Cloud')
        ->and($connection->credential('access_token'))->toBe('secret-token')
        ->and($connection->setting('workspace'))->toBe('acme');
});

it('stores a connection\'s secret encrypted, with a webhook for source control', function () {
    livewire(ManageSourceControl::class)
        ->callAction('create', [
            'kind' => ConnectionKind::SourceControl->value,
            'driver' => 'bitbucket',
            'name' => 'Bitbucket',
            'credentials' => ['access_token' => 'secret-token'],
            'settings' => ['workspace' => 'acme', 'add_repositories' => true],
        ])
        ->assertHasNoFormErrors();

    $connection = Connection::query()->sole();

    expect($connection->tenant_id)->toBe($this->tenant->id)
        ->and($connection->addsRepositories())->toBeTrue()
        ->and($connection->credential('access_token'))->toBe('secret-token')
        ->and($connection->getRawOriginal('credentials'))->not->toContain('secret-token')
        ->and($connection->webhook_key)->not->toBeNull()
        ->and($connection->webhook_secret)->not->toBeNull();
});

it('adds the webhook to the workspace, with the connection\'s address and secret', function () {
    $connection = Connection::factory()->create([
        'tenant_id' => $this->tenant->id,
        'credentials' => ['access_token' => 'secret-token'],
        'settings' => ['workspace' => 'acme'],
        'webhook_key' => 'hook-one',
        'webhook_secret' => 'hook-secret',
    ]);

    Http::fake(['api.bitbucket.org/2.0/workspaces/acme/hooks*' => Http::sequence()
        ->push(['values' => [['uuid' => '{other}', 'url' => 'https://elsewhere.example/hook']]])
        ->push(['uuid' => '{new}'], 201)]);

    livewire(ManageSourceControl::class)
        ->callAction(TestAction::make('webhook')->table($connection))
        ->assertNotified('The webhook is in place');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.bitbucket.org/2.0/workspaces/acme/hooks'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['url'] === url('/api/webhooks/hook-one')
        && $request['secret'] === 'hook-secret'
        && $request['active'] === true
        && $request['events'] === ['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected', 'pullrequest:comment_created', 'pullrequest:approved', 'pullrequest:unapproved', 'pullrequest:changes_request_created', 'pullrequest:changes_request_removed']);
});

it('updates the webhook that is already there instead of adding a second', function () {
    $connection = Connection::factory()->create([
        'tenant_id' => $this->tenant->id,
        'settings' => ['workspace' => 'acme'],
        'webhook_key' => 'hook-one',
        'webhook_secret' => 'hook-secret',
    ]);

    Http::fake(['api.bitbucket.org/2.0/workspaces/acme/hooks*' => Http::sequence()
        ->push(['values' => [['uuid' => '{abc-123}', 'url' => url('/api/webhooks/hook-one')]]])
        ->push(['uuid' => '{abc-123}'])]);

    livewire(ManageSourceControl::class)
        ->callAction(TestAction::make('webhook')->table($connection))
        ->assertNotified('The webhook is in place');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.bitbucket.org/2.0/workspaces/acme/hooks/%7Babc-123%7D'
        && $request['secret'] === 'hook-secret');
});

it('says which scope is missing when the token may not manage webhooks', function () {
    $connection = Connection::factory()->create([
        'tenant_id' => $this->tenant->id,
        'settings' => ['workspace' => 'acme'],
        'webhook_key' => 'hook-one',
        'webhook_secret' => 'hook-secret',
    ]);

    Http::fake(['api.bitbucket.org/*' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);

    livewire(ManageSourceControl::class)
        ->callAction(TestAction::make('webhook')->table($connection))
        ->assertNotified(
            Notification::make()
                ->title('The webhook could not be added')
                ->body('The token may not manage webhooks: give it the webhook scope, or add the webhook to each repository by hand.')
                ->danger(),
        );

    Http::assertSentCount(1);
});

it('keeps a secret that an edit leaves empty, and never sends it to the form', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id, 'credentials' => ['access_token' => 'secret-token']]);

    livewire(ManageSourceControl::class)
        ->mountAction(TestAction::make('edit')->table($connection))
        ->assertDontSee('secret-token')
        ->setActionData(['name' => 'Renamed', 'settings' => ['workspace' => 'acme']])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($connection->refresh()->name)->toBe('Renamed')
        ->and($connection->credential('access_token'))->toBe('secret-token');
});

it('shows only the tenant\'s own records', function () {
    $own = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])]);

    livewire(ManageRepositories::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$this->foreign]);
});

it('checks against the host whether a repository can be reviewed', function () {
    config(['review.docker.enabled' => false, 'review.diff.enabled' => false]);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bitbucket Cloud']);
    $repository = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'shop']);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
    ]);

    $states = fn (): array => collect(app(RepositoryReadiness::class)->checks($repository->refresh()))->pluck('state', 'label')->all();

    // Nothing set up: no AI provider, a runner that is turned off, and no webhook to start a review.
    expect($states())->toBe([
        'Reviews' => 'ok',
        'Connection can read the repository' => 'ok',
        'AI provider' => 'problem',
        'Reviewers available' => 'unknown',
        'Runner' => 'problem',
        'Webhook' => 'problem',
        'Ticket source' => 'unknown',
    ])->and(app(RepositoryReadiness::class)->ready($repository))->toBeFalse();

    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);
    config(['review.docker.enabled' => true]);

    // Nothing is needed in the repository, but nothing starts a review without the webhook.
    expect($states())->toBe([
        'Reviews' => 'ok',
        'Connection can read the repository' => 'ok',
        'AI provider' => 'ok',
        'Reviewers available' => 'unknown',
        'Runner' => 'ok',
        'Webhook' => 'problem',
        'Ticket source' => 'unknown',
    ]);

    // A delivery for another repository of the connection proves nothing for this one.
    $connection->update(['webhook_received_at' => now()->subMinutes(3)]);

    expect($states()['Webhook'])->toBe('unknown');

    $repository->forceFill(['webhook_received_at' => now()->subMinutes(3)])->save();

    expect($states()['Webhook'])->toBe('ok')
        ->and(app(RepositoryReadiness::class)->ready($repository))->toBeTrue();

    expect(collect(app(RepositoryReadiness::class)->checks($repository))->firstWhere('label', 'Webhook')['note'])->toBe('Last delivery 3 minutes ago.');

    // Turned off, or with the reviewers its runner reported, it says so; off still lets a person start one.
    $repository->forceFill(['reviews_enabled' => false, 'review_available_providers' => ['claude-code', 'codex-cli']])->save();
    $notes = collect(app(RepositoryReadiness::class)->checks($repository))->pluck('note', 'label');

    expect($notes['Reviews'])->toStartWith('Off: nothing starts a review by itself')
        ->and($notes['Reviewers available'])->toBe(ReviewProvider::ClaudeCode->text().', '.ReviewProvider::CodexCli->text())
        ->and($notes['Runner'])->toBe('Docker on this server, as the platform. Nothing is needed in the repository.')
        ->and(app(RepositoryReadiness::class)->ready($repository))->toBeTrue();

    // The last review found no reviewer it could run.
    $repository->forceFill(['review_available_providers' => []])->save();

    expect(collect(app(RepositoryReadiness::class)->checks($repository))->firstWhere('label', 'Reviewers available')['state'])->toBe('problem');

    livewire(ManageRepositories::class)->assertActionVisible(TestAction::make('readiness')->table($repository));
});

it('says so when the token cannot see the repository', function () {
    $repository = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bitbucket Cloud']), 'slug' => 'gone']);

    Http::fake(['api.bitbucket.org/*' => Http::response([], 404)]);

    expect(collect(app(RepositoryReadiness::class)->checks($repository))->firstWhere('label', 'Connection can read the repository'))->toBe([
        'label' => 'Connection can read the repository',
        'note' => 'Bitbucket Cloud cannot see acme/gone: check its name and the token.',
        'state' => 'problem',
    ]);
});

it('lists a tenant\'s reviews with their cost', function () {
    $repository = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])]);

    $run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => $repository->slug,
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED',
        'cost' => 1.25, 'started_at' => now(), 'finished_at' => now(), 'runner' => 'docker', 'agent' => 'ocr', 'model' => 'claude-opus-5-5',
    ]);

    // Two reviewers can use one model, so the list names both.
    livewire(ListReviewRuns::class)->assertCanSeeTableRecords([$run])->assertSee('$1.25')->assertSee('OpenCodeReview · claude-opus-5-5');

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('feature/export');

    // A run is addressed by its uuid only, so its address says nothing about the others.
    $this->get("/app/acme/review-runs/{$run->id}")->assertNotFound();
});

it('shows on a run which ticket the review read, or why it could not', function () {
    $repository = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])]);

    $run = fn (?array $ticket, string $commit): ReviewRun => ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => $repository->slug,
        'branch' => 'feature/export', 'commit' => str_repeat($commit, 40), 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
        'context' => ['ticket' => $ticket],
    ]);

    $found = $run(['reference' => 'PROJ-124', 'source' => 'Asana', 'url' => 'https://tickets.example.com/PROJ-124', 'markdown' => '# Task', 'error' => null], 'a');
    $missing = $run(['reference' => 'PROJ-999', 'source' => 'Asana', 'url' => null, 'markdown' => null, 'error' => 'No ticket in Asana matches this reference.'], 'b');
    $none = $run(null, 'c');

    $this->get("/app/acme/review-runs/{$found->uuid}")->assertOk()
        ->assertSee('PROJ-124')
        ->assertSee('https://tickets.example.com/PROJ-124');

    $this->get("/app/acme/review-runs/{$missing->uuid}")->assertOk()
        ->assertSee('PROJ-999')
        ->assertSee('No ticket in Asana matches this reference.');

    $this->get("/app/acme/review-runs/{$none->uuid}")->assertOk()->assertSee('None named in the title');
});

it('lets an administrator add a tenant', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(null);

    livewire(ManageTenants::class)
        ->callAction('create', ['name' => 'Globex', 'slug' => 'globex'])
        ->assertHasNoFormErrors();

    expect(Tenant::query()->where('slug', 'globex')->exists())->toBeTrue();
});

it('adds a repository from its connection and name alone, with the workspace and the stack found for it', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id, 'settings' => ['workspace' => 'acme']]);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/composer.json' => Http::response('{"require": {"laravel/framework": "^13.0"}}'),
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
    ]);

    livewire(ManageRepositories::class)
        // Typed by hand into a forged request: the connection decides the workspace.
        ->callAction('create', ['connection_id' => $connection->id, 'slug' => 'shop', 'workspace' => 'elsewhere'])
        ->assertHasNoFormErrors();

    $repository = Repository::query()->where('slug', 'shop')->sole();

    expect($repository->workspace)->toBe('acme')
        ->and($repository->review_stack)->toBe(['laravel'])
        // Nothing chosen: it follows the platform's runner.
        ->and($repository->review_runner)->toBeNull()
        ->and($repository->connection_id)->toBe($connection->id);
});

it('adds a repository without a stack when its host cannot say what it is built on', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);

    Http::fake(['api.bitbucket.org/*' => Http::response([], 500)]);

    livewire(ManageRepositories::class)
        ->callAction('create', ['connection_id' => $connection->id, 'slug' => 'shop'])
        ->assertHasNoFormErrors();

    expect(Repository::query()->where('slug', 'shop')->sole()->review_stack)->toBe([]);
});

it('refuses a repository the connection already has', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'shop']);

    Http::fake();

    livewire(ManageRepositories::class)
        ->callAction('create', ['connection_id' => $connection->id, 'slug' => 'shop'])
        ->assertHasFormErrors(['slug']);

    Http::assertNothingSent();
});

it('finds the repositories of a connection\'s workspace by a part of their name', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id, 'settings' => ['workspace' => 'acme']]);

    Http::fake(['api.bitbucket.org/2.0/repositories/acme*' => Http::response(['values' => [
        ['slug' => 'web-shop', 'name' => 'Web Shop'],
        ['slug' => 'shop-api', 'name' => 'shop-api'],
    ]])]);

    expect($connection->driver()->searchRepositories($connection, ' sh"op '))->toBe([
        'web-shop' => 'Web Shop (web-shop)',
        'shop-api' => 'shop-api',
    ]);

    Http::assertSent(fn (Request $request): bool => $request['q'] === 'name ~ "shop"'
        && $request['sort'] === '-updated_on'
        && $request->hasHeader('Authorization', 'Bearer bitbucket-token'));
});

it('refuses a repository on another tenant\'s connection', function () {
    livewire(ManageRepositories::class)
        ->callAction('create', [
            'connection_id' => $this->foreign->connection_id,
            'workspace' => 'acme',
            'slug' => 'stolen',
        ])
        ->assertHasFormErrors(['connection_id']);

    expect(Repository::query()->withoutGlobalScopes()->where('slug', 'stolen')->exists())->toBeFalse();
});

it('lists whether each repository is reviewed by itself, which an admin turns from the list, and its reviews of the last 30 days', function () {
    $repository = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])]);
    $run = fn (string $status, int $daysAgo) => ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => $repository->slug, 'branch' => 'main',
        'commit' => str_repeat('a', 40), 'status' => $status, 'started_at' => now()->subDays($daysAgo),
    ]);
    $run(ReviewRun::STATUS_FINISHED, 2);
    $run(ReviewRun::STATUS_FAILED, 29);
    // Too long ago, or never a review.
    $run(ReviewRun::STATUS_FINISHED, 31);
    $run(ReviewRun::STATUS_SKIPPED, 1);

    livewire(ManageRepositories::class)
        ->assertTableColumnStateSet('reviews_last_30_days', 2, $repository)
        ->call('updateTableColumnState', 'reviews_enabled', (string) $repository->getKey(), false);

    expect($repository->refresh()->reviews_enabled)->toBeFalse();

    $member = User::factory()->create();
    $member->tenants()->attach($this->tenant);
    $this->actingAs($member);

    livewire(ManageRepositories::class)
        ->call('updateTableColumnState', 'reviews_enabled', (string) $repository->getKey(), true);

    expect($repository->refresh()->reviews_enabled)->toBeFalse();
});

it('adds a repository that is not reviewed by itself when the toggle is off', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);

    livewire(ManageRepositories::class)
        ->callAction('create', ['connection_id' => $connection->id, 'workspace' => 'acme', 'slug' => 'manual', 'reviews_enabled' => false])
        ->assertHasNoFormErrors();

    expect(Repository::query()->where('slug', 'manual')->sole()->reviews_enabled)->toBeFalse();

    // One added again after it was removed is reviewed by itself as the form says now.
    $removed = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'workspace' => 'acme', 'slug' => 'back', 'reviews_enabled' => true]);
    $removed->delete();

    livewire(ManageRepositories::class)
        ->callAction('create', ['connection_id' => $connection->id, 'workspace' => 'acme', 'slug' => 'back', 'reviews_enabled' => false])
        ->assertHasNoFormErrors();

    expect($removed->refresh())->trashed()->toBeFalse()->reviews_enabled->toBeFalse();
});

it('lets a repository choose its runner among the ones that are set up, or follow the platform\'s', function () {
    config(['review.docker.enabled' => true, 'review.cloudflare.local' => false]);

    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $create = fn (string $slug, ?string $runner) => livewire(ManageRepositories::class)->callAction('create', [
        'connection_id' => $connection->id, 'workspace' => 'acme', 'slug' => $slug, 'review_runner' => $runner,
    ]);

    $create('first', 'diff')->assertHasNoFormErrors();
    $create('unknown', 'elsewhere')->assertHasFormErrors(['review_runner']);
    // Not set up, so not offered.
    $create('cloud', 'cloudflare')->assertHasFormErrors(['review_runner']);
    $create('second', null)->assertHasNoFormErrors();

    expect(Repository::query()->where('slug', 'first')->sole()->review_runner)->toBe('diff')
        ->and(Repository::query()->where('slug', 'second')->sole()->review_runner)->toBeNull()
        ->and(Repository::query()->where('slug', 'second')->sole()->runnerName())->toBe('docker');
});

it('warns the administrator who changes how a review works, and shows what ships beside it', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(null);
    $copy = ReviewInstructions::customiseBase(ReviewInstructions::STRUCTURE);
    $guide = ReviewInstruction::query()->create(['key' => 'stack-laravel', 'title' => 'Laravel', 'stack' => 'laravel', 'position' => 30, 'body' => 'Ours.']);

    livewire(EditDefaultInstruction::class, ['record' => $copy->getKey()])->assertOk()
        ->assertSee('This is how a review works, not what it looks for')
        ->assertSee('What this release ships');

    // And it can be saved under the name it has.
    livewire(EditDefaultInstruction::class, ['record' => $copy->getKey()])
        ->fillForm(['body' => 'Our own flow.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($copy->refresh()->only(['key', 'body']))->toBe(['key' => 'skill', 'body' => 'Our own flow.']);

    // A stack's guide is what a review looks for: no warning, and the shipped text to compare with.
    livewire(EditDefaultInstruction::class, ['record' => $guide->getKey()])->assertOk()
        ->assertDontSee('This is how a review works, not what it looks for')
        ->assertSee('What this release ships');

    // A tenant cannot make a file under the structure's name.
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);

    livewire(CreateTenantInstruction::class)
        ->fillForm(['key' => 'skill', 'title' => 'Ours', 'position' => 5, 'body' => 'A tenant rewrites the report.'])
        ->call('create')
        ->assertHasFormErrors(['key']);
});

it('sets aside a review that did not finish, which then leaves what needs action and stays under All', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])]);
    $run = fn (string $status): ReviewRun => ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => $repository->slug,
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'status' => $status, 'started_at' => now(),
    ]);
    $failed = $run(ReviewRun::STATUS_FAILED);
    $finished = $run(ReviewRun::STATUS_FINISHED);

    livewire(ListReviewRuns::class)
        ->assertTableActionHidden('dismiss', $finished)
        ->callTableAction('dismiss', $failed);

    expect($failed->refresh()->dismissed_at)->not->toBeNull();

    livewire(ListReviewRuns::class, ['activeTab' => 'failed'])->assertCanNotSeeTableRecords([$failed]);
    livewire(ListReviewRuns::class, ['activeTab' => 'action'])->assertCanNotSeeTableRecords([$failed]);
    livewire(ListReviewRuns::class, ['activeTab' => 'all'])->assertCanSeeTableRecords([$failed]);
});

it('adds a ticket service and the references it answers as one, and keeps it off Connections', function () {
    livewire(ManageTickets::class)
        ->callAction('create', [
            'connection' => ['driver' => 'asana', 'name' => 'Asana', 'credentials' => ['access_token' => 'asana-token'], 'settings' => ['workspace' => '1200']],
            'style' => TicketStyle::Key->value,
            'prefixes' => ['PROJ'],
            'position' => 0,
        ])
        ->assertHasNoFormErrors();

    $source = TicketSource::query()->sole();

    expect($source->only(['tenant_id', 'name', 'prefixes']))->toBe(['tenant_id' => $this->tenant->id, 'name' => 'Asana', 'prefixes' => ['PROJ']])
        ->and($source->integration->only(['tenant_id', 'kind', 'driver']))->toBe(['tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::Tickets, 'driver' => 'asana'])
        ->and($source->integration->credential('access_token'))->toBe('asana-token');

    // An edit with the secret left empty keeps it, and renames both.
    livewire(ManageTickets::class)
        ->callTableAction('edit', $source, ['connection' => ['name' => 'Asana projects', 'credentials' => ['access_token' => '']], 'prefixes' => ['PROJ', 'SHOP']])
        ->assertHasNoFormErrors();

    $source->refresh();

    expect($source->name)->toBe('Asana projects')
        ->and($source->prefixes)->toBe(['PROJ', 'SHOP'])
        ->and($source->integration->name)->toBe('Asana projects')
        ->and($source->integration->credential('access_token'))->toBe('asana-token');

    // Not on Connections; one that no source uses still is.
    $unused = Connection::factory()->create(['tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::Tickets, 'driver' => 'jira', 'name' => 'Old Jira']);

    livewire(ManageSourceControl::class)->assertCanNotSeeTableRecords([$source->integration, $unused]);
    // What no other page shows stays in view, on a page of its own.
    livewire(ManageConnections::class)
        ->assertCanNotSeeTableRecords([$source->integration])
        ->assertCanSeeTableRecords([$unused]);

    // Deleting the last source that reads a connection deletes the connection.
    livewire(ManageTickets::class)->callTableAction('delete', $source);

    expect(Connection::withTrashed()->whereKey($source->connection_id)->exists())->toBeFalse();
});

it('links a repository and a review to their place on the host', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])->id, 'workspace' => 'acme', 'slug' => 'shop', 'review_runner' => null]);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);

    livewire(ManageRepositories::class)
        ->assertTableActionHasUrl('host', 'https://bitbucket.org/acme/shop', $repository);

    livewire(ListReviewRuns::class)->assertTableActionHasUrl('pullRequest', 'https://bitbucket.org/acme/shop/pull-requests/570', $run);
});

it('detects the repository and the branch from a pull request\'s link, number or ticket key, filling in the fields below', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])->id, 'workspace' => 'acme', 'slug' => 'shop']);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/39' => Http::response(['id' => 39, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export']]]),
        '*' => Http::response([], 404),
    ]);
    $detect = fn (string $input, ?int $repositoryId = null) => livewire(ListReviewRuns::class)
        ->mountAction('review')
        ->set('mountedActions.0.data.repository_id', $repositoryId)
        ->set('mountedActions.0.data.detect', $input);

    $detect('https://bitbucket.org/acme/shop/pull-requests/39/overview')
        ->assertSet('mountedActions.0.data.repository_id', $repository->id)
        ->assertSet('mountedActions.0.data.branch', 'feature/export')
        ->assertSet('mountedActions.0.data.detected', 'Found: feature/export.');

    // A number needs the repository first.
    $detect('39')->assertSet('mountedActions.0.data.detected', 'Pick the repository below first, then detect again.');
    $detect('#39', $repository->id)->assertSet('mountedActions.0.data.branch', 'feature/export');

    OpenPullRequest::query()->create(['repository_id' => $repository->id, 'pull_request_id' => 40])->forceFill(['title' => 'VZ-959 Export the orders', 'branch' => 'feature/vz-959'])->save();

    $detect('vz-959')
        ->assertSet('mountedActions.0.data.repository_id', $repository->id)
        ->assertSet('mountedActions.0.data.branch', 'feature/vz-959');

    $detect('https://bitbucket.org/acme/elsewhere/pull-requests/1')
        ->assertSet('mountedActions.0.data.branch', null)
        ->assertSet('mountedActions.0.data.detected', 'Nothing found for "https://bitbucket.org/acme/elsewhere/pull-requests/1": no open pull request of this team\'s repositories, or its repository is on more than one connection. Pick the repository below and detect again, or fill in the branch.');

    // A detection that fails after one that found something clears what that one filled in.
    livewire(ListReviewRuns::class)
        ->mountAction('review')
        ->set('mountedActions.0.data.detect', 'https://bitbucket.org/acme/shop/pull-requests/39')
        ->set('mountedActions.0.data.detect', 'https://bitbucket.org/acme/elsewhere/pull-requests/1')
        ->assertSet('mountedActions.0.data.branch', null)
        ->assertSet('mountedActions.0.data.repository_id', null);

    // So does emptying the field; a branch typed by hand since stays.
    livewire(ListReviewRuns::class)
        ->mountAction('review')
        ->set('mountedActions.0.data.detect', 'https://bitbucket.org/acme/shop/pull-requests/39')
        ->set('mountedActions.0.data.detect', '')
        ->assertSet('mountedActions.0.data.branch', null)
        ->assertSet('mountedActions.0.data.detected', null)
        ->set('mountedActions.0.data.detect', 'https://bitbucket.org/acme/shop/pull-requests/39')
        ->set('mountedActions.0.data.branch', 'feature/mine')
        ->set('mountedActions.0.data.detect', '')
        ->assertSet('mountedActions.0.data.branch', 'feature/mine');

    // A ticket key whole: VZ-95 is not VZ-959.
    livewire(ListReviewRuns::class)
        ->mountAction('review')
        ->set('mountedActions.0.data.detect', 'VZ-95')
        ->assertSet('mountedActions.0.data.branch', null);

    // The same repository on a second connection: the one chosen is kept.
    $second = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])->id, 'workspace' => 'acme', 'slug' => 'shop']);

    $detect('https://bitbucket.org/acme/shop/pull-requests/39', $second->id)->assertSet('mountedActions.0.data.repository_id', $second->id);
    $detect('https://bitbucket.org/acme/shop/pull-requests/39')->assertSet('mountedActions.0.data.repository_id', null);

    // A link left in the branch is no branch.
    livewire(ListReviewRuns::class)
        ->callAction('review', ['repository_id' => $repository->id, 'branch' => 'https://bitbucket.org/acme/shop/pull-requests/39'])
        ->assertHasFormErrors(['branch']);
});

it('says what to set up when a review is asked for on a runner that is not', function () {
    config(['review.docker.enabled' => false]);
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id])->id, 'slug' => 'shop', 'review_runner' => 'docker']);

    livewire(ListReviewRuns::class)
        ->callAction('review', ['repository_id' => $repository->id, 'branch' => 'feature/export'])
        ->assertNotified(Notification::make()->title('The review could not be started')->body("{$repository->fullName()} reviews on Docker on this server, which is not set up on this installation. ".app(ContainerRunner::class)->setupHint().' Or pick another runner under "Runs on".')->danger());

    $readiness = collect(app(RepositoryReadiness::class)->checks($repository))->firstWhere('label', 'Runner');

    expect($readiness['state'])->toBe('problem')->and($readiness['note'])->toContain('REVIEW_DOCKER_ENABLED=true');
});
