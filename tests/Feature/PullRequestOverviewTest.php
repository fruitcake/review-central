<?php

use App\Enums\ReviewsFrom;
use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Jobs\RunContainerReview;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['review.docker.enabled' => true]);
    Queue::fake();
    Http::fake();

    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_runner' => 'docker']);
    $this->repository->integration->update(['webhook_key' => 'hook-overview', 'webhook_secret' => 'secret']);
    $this->repository->tenant->update(['review_command_accounts' => ['trusted-dev']]);
});

/**
 * A Bitbucket pull request update, by the given author.
 *
 * @param  array<string, mixed>  $attributes
 */
function deliverPullRequest(array $attributes = [], string $nickname = 'outsider'): TestResponse
{
    $body = json_encode(['pullrequest' => $attributes + [
        'id' => 7,
        'title' => 'Export to CSV',
        'state' => 'OPEN',
        'draft' => false,
        'updated_on' => '2026-11-19T10:00:00+00:00',
        'author' => ['display_name' => ucfirst($nickname), 'account_id' => "id-{$nickname}", 'nickname' => $nickname],
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('b', 12)]],
    ], 'repository' => ['full_name' => 'acme/shop']]);

    return test()->call('POST', '/api/webhooks/hook-overview', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_EVENT_KEY' => 'pullrequest:updated',
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
    ], $body);
}

it('lists a pull request of a repository that does not review by itself, and starts nothing', function () {
    $this->repository->update(['reviews_enabled' => false]);

    deliverPullRequest()->assertOk();

    Queue::assertNothingPushed();
    expect(OpenPullRequest::query()->sole())
        ->title->toBe('Export to CSV')
        ->author->toBe('Outsider')
        ->author_nickname->toBe('outsider')
        ->branch->toBe('feature/export')
        ->awaiting_approval->toBeFalse();

    // Merged or declined, it is gone from the list.
    deliverPullRequest(['state' => 'MERGED', 'updated_on' => '2026-11-19T10:05:00+00:00'])->assertOk();

    expect(OpenPullRequest::query()->count())->toBe(0);
});

it('reviews only the trusted accounts\' pull requests by themselves where only theirs are, and lets the others wait', function () {
    $this->repository->update(['reviews_from' => ReviewsFrom::Trusted]);

    deliverPullRequest()->assertOk();

    Queue::assertNothingPushed();
    expect(OpenPullRequest::query()->sole()->awaiting_approval)->toBeTrue();
    // Shown on the pull request as waiting, leading to the overview.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/statuses/build')
        && $request['state'] === 'INPROGRESS'
        && str_contains((string) $request['url'], '/pull-requests')
        && str_contains((string) $request['description'], 'Waiting'));

    // Matched by nickname whatever its case.
    deliverPullRequest(['id' => 8, 'source' => ['branch' => ['name' => 'feature/import'], 'commit' => ['hash' => str_repeat('c', 12)]]], 'Trusted-Dev')->assertOk();

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/import');
});

it('starts a waiting pull request\'s review from the overview', function () {
    $this->repository->update(['reviews_from' => ReviewsFrom::Trusted]);
    deliverPullRequest()->assertOk();

    $user = User::factory()->create();
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    $record = OpenPullRequest::query()->sole();

    livewire(ListPullRequests::class)
        ->assertCanSeeTableRecords([$record])
        ->assertSee('Waiting for approval')
        ->callTableAction('start', $record)
        ->assertNotified('The review is started');

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/export');
    expect($record->refresh()->awaiting_approval)->toBeFalse();
});

it('lists only the open tenant\'s pull requests', function () {
    $other = Repository::factory()->create(['slug' => 'elsewhere']);
    OpenPullRequest::query()->create(['repository_id' => $other->id, 'pull_request_id' => 1]);
    deliverPullRequest()->assertOk();

    $user = User::factory()->create();
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    livewire(ListPullRequests::class)
        ->assertCanSeeTableRecords(OpenPullRequest::query()->where('repository_id', $this->repository->id)->get())
        ->assertCanNotSeeTableRecords(OpenPullRequest::query()->where('repository_id', $other->id)->get());
});
