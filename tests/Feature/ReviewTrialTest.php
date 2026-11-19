<?php

use App\Exceptions\ReviewTrialRefused;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runners\Container\Trial;
use App\Services\ReviewTrial;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['review.docker.enabled' => true]);
    Queue::fake();
    Http::preventStrayRequests();

    $this->tenant = Tenant::factory()->create();
    $this->connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
});

/**
 * @param  array<string, mixed>  $pullRequest
 */
function fakePullRequest(array $pullRequest = [], string $slug = 'shop'): void
{
    Http::fake([
        "api.bitbucket.org/2.0/repositories/acme/{$slug}/pullrequests/12" => Http::response($pullRequest + [
            'id' => 12,
            'state' => 'OPEN',
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
            'destination' => ['branch' => ['name' => 'main']],
        ]),
        // The repository has no composer.json to read a stack from.
        '*' => Http::response([], 404),
    ]);
}

it('tries an open pull request of a repository that is not set up, and adds it without reviewing it', function () {
    fakePullRequest();

    $repository = app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12/diff', [
        'provider' => 'claude-code',
        'model' => 'claude-opus-5-5',
        'effort' => 'high',
    ]);

    expect($repository->exists)->toBeTrue()
        ->and($repository->only(['tenant_id', 'connection_id', 'workspace', 'slug', 'review_runner', 'reviews_enabled']))->toBe([
            'tenant_id' => $this->tenant->id,
            'connection_id' => $this->connection->id,
            'workspace' => 'acme',
            'slug' => 'shop',
            // It follows the platform's runner, as any repository that picks none.
            'review_runner' => null,
            'reviews_enabled' => false,
        ]);

    Queue::assertPushed(RunContainerReview::class, 1);

    // In the list at once, as queued, with what is known of it.
    expect(ReviewRun::query()->sole()->only(['status', 'custom', 'pull_request_id', 'agent', 'commit']))
        ->toBe(['status' => ReviewRun::STATUS_QUEUED, 'custom' => true, 'pull_request_id' => 12, 'agent' => 'claude-code', 'commit' => '']);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->repositoryId === $repository->id
        && $job->branch === 'feature/export'
        && $job->commit === null
        && $job->rounds === 1
        && $job->trial == new Trial(pullRequestId: 12, destination: 'main', provider: 'claude-code', model: 'claude-opus-5-5', effort: 'high', sandbox: 'docker'));
});

it('tries a merged pull request on the commit it was merged in, and leaves a repository that is set up as it is', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'slug' => 'shop', 'review_runner' => 'docker']);
    fakePullRequest(['state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('d', 12)]]);

    app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12', ['ignore_existing' => false]);

    expect(Repository::query()->count())->toBe(1)
        ->and($repository->refresh()->only(['review_runner', 'reviews_enabled']))->toBe(['review_runner' => 'docker', 'reviews_enabled' => true]);

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->repositoryId === $repository->id
        && $job->trial?->mergeCommit === str_repeat('d', 12)
        && $job->trial->ignoreExisting === false);
});

it('says what a custom run is to the review\'s scripts, and only what was chosen', function () {
    expect((new Trial(pullRequestId: 12, destination: 'main'))->environment())->toBe([
        'BITBUCKET_PR_ID' => '12',
        'BITBUCKET_PR_DESTINATION_BRANCH' => 'main',
        'REVIEW_CUSTOM_PROVIDER' => 'default',
        'REVIEW_CUSTOM' => 'true',
    ])->and((new Trial(pullRequestId: 12, destination: 'main', provider: 'codex-cli', model: 'gpt-6', effort: 'low', verify: false, ignoreExisting: false))->environment())->toBe([
        'BITBUCKET_PR_ID' => '12',
        'BITBUCKET_PR_DESTINATION_BRANCH' => 'main',
        'REVIEW_CUSTOM_PROVIDER' => 'codex-cli',
        'REVIEW_CUSTOM' => 'true',
        'REVIEW_CUSTOM_MODEL' => 'gpt-6',
        'REVIEW_CUSTOM_EFFORT' => 'low',
        'REVIEW_CUSTOM_VERIFY' => 'false',
        'REVIEW_CUSTOM_EXISTING' => 'keep',
    ]);
});

it('refuses what it cannot try, and leaves no repository behind', function (string $url, array $pullRequest, string $message) {
    fakePullRequest($pullRequest);

    expect(fn () => app(ReviewTrial::class)->start($this->tenant, $url))->toThrow(ReviewTrialRefused::class, $message);

    expect(Repository::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'not a pull request' => ['https://bitbucket.org/acme/shop/src/main', [], 'not the address of a pull request on Bitbucket or GitHub'],
    'another workspace' => ['https://bitbucket.org/elsewhere/shop/pull-requests/12', [], 'No source control connection here is for the workspace elsewhere'],
    'unknown pull request' => ['https://bitbucket.org/acme/shop/pull-requests/99', [], 'finds no pull request 99'],
    'declined' => ['https://bitbucket.org/acme/shop/pull-requests/12', ['state' => 'DECLINED'], 'Only an open or a merged pull request'],
    'fast-forward' => ['https://bitbucket.org/acme/shop/pull-requests/12', ['state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('a', 12)]], 'merged by fast-forward'],
]);

it('needs a runner that runs a reviewer for a reviewer', function () {
    config(['review.docker.enabled' => false]);
    fakePullRequest();

    expect(fn () => app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12', ['provider' => 'claude-code']))
        ->toThrow(ReviewTrialRefused::class, 'needs Docker or a Cloudflare sandbox');
});

it('starts a trial from the list of reviews', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();
    fakePullRequest();

    livewire(ListReviewRuns::class)
        ->callAction('try', ['url' => 'https://bitbucket.org/acme/shop/pull-requests/12', 'model' => 'sonnet', 'ignore_existing' => false])
        ->assertHasNoFormErrors()
        ->assertNotified('The trial is started');

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->model === 'sonnet' && $job->trial->ignoreExisting === false && $job->trial->verify === false);

    livewire(ListReviewRuns::class)
        ->callAction('try', ['url' => 'https://bitbucket.org/elsewhere/shop/pull-requests/12'])
        ->assertNotified('This pull request cannot be tried');
});

it('finds the repository it added again when the address writes the workspace otherwise than the connection', function () {
    $this->connection->update(['settings' => ['workspace' => 'Acme']]);
    Http::fake([
        '*/pullrequests/12' => Http::response(['id' => 12, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]], 'destination' => ['branch' => ['name' => 'main']]]),
        '*' => Http::response([], 404),
    ]);

    $first = app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12');
    $second = app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12');

    expect($first->workspace)->toBe('Acme')
        ->and($second->is($first))->toBeTrue()
        ->and($this->tenant->repositories()->count())->toBe(1);
});

it('refuses an open pull request from a fork on Bitbucket, whose branch is nowhere to clone', function () {
    fakePullRequest([
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)], 'repository' => ['full_name' => 'someone/shop']],
        'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'acme/shop']],
    ]);

    expect(fn () => app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12'))
        ->toThrow(ReviewTrialRefused::class, 'comes from a fork');
});

it('tries an open Bitbucket pull request from the repository itself, however its name is written', function () {
    fakePullRequest([
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)], 'repository' => ['full_name' => 'Acme/shop']],
        'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'acme/shop']],
    ]);

    app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12');

    Queue::assertPushed(RunContainerReview::class, 1);
});

it('runs a trial with a reviewer on the repository\'s own runner, and on the platform\'s when the repository reviews from the diff', function () {
    config(['review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'token', 'review.cloudflare.disk' => 'r2']);
    Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'slug' => 'shop', 'review_runner' => 'cloudflare']);
    fakePullRequest();

    app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12', ['provider' => 'codex-cli']);

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->sandbox === 'cloudflare' && $job->trial->provider === 'codex-cli');

    Repository::query()->where('slug', 'shop')->update(['review_runner' => 'diff']);

    app(ReviewTrial::class)->start($this->tenant, 'https://bitbucket.org/acme/shop/pull-requests/12', ['provider' => 'codex-cli']);

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->sandbox === 'docker');
});

it('re-runs a review as a trial set up as it was, and posts it only when asked', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();
    fakePullRequest();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'slug' => 'shop']);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => str_repeat('a', 40), 'pull_request_id' => 12, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
        'runner' => 'docker', 'agent' => 'claude-code', 'model' => 'claude-opus-5-5', 'effort' => 'high',
    ]);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->mountAction('rerun')
        ->assertActionDataSet(['post' => false, 'runner' => 'docker', 'provider' => 'claude-code', 'model' => 'claude-opus-5-5', 'effort' => 'high'])
        ->setActionData(['model' => 'claude-sonnet-5-5'])
        ->callMountedAction()
        ->assertNotified('The re-run is started');

    // A trial: kept off the pull request, with what was changed.
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->pullRequestId === 12
        && $job->trial->model === 'claude-sonnet-5-5' && $job->trial->effort === 'high' && $job->trial->provider === 'claude-code');
});
