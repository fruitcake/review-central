<?php

use App\Enums\ConnectionKind;
use App\Enums\TicketStyle;
use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Filament\App\Resources\PullRequests\PullRequestResource;
use App\Jobs\DescribePullRequest;
use App\Jobs\RunContainerReview;
use App\Llm\Agents\Describer;
use App\Models\Connection;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\TicketSource;
use App\Models\User;
use App\Services\PullRequestTurn;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Queue::fake();
    Http::fake();

    $this->repository = Repository::factory()->create(['slug' => 'shop', 'reviews_enabled' => false]);
    $this->repository->integration->update(['webhook_key' => 'hook-turn', 'webhook_secret' => 'secret']);
});

/**
 * A Bitbucket delivery about pull request 7, by Alex, with Robin asked to review.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $extra
 */
function deliverTurn(array $attributes = [], string $event = 'pullrequest:updated', array $extra = []): TestResponse
{
    $body = json_encode($extra + ['pullrequest' => $attributes + [
        'id' => 7,
        'title' => 'DEV-5007 Export the orders',
        'state' => 'OPEN',
        'draft' => false,
        'created_on' => '2026-11-12T09:00:00+00:00',
        'updated_on' => '2026-11-19T10:00:00+00:00',
        'author' => ['display_name' => 'Alex Author', 'account_id' => 'id-alex', 'nickname' => 'alex'],
        'source' => ['branch' => ['name' => 'DEV-5007'], 'commit' => ['hash' => str_repeat('b', 12)]],
        'destination' => ['branch' => ['name' => 'develop']],
        'reviewers' => [['display_name' => 'Robin Reviewer', 'account_id' => 'id-robin', 'nickname' => 'robin']],
        'participants' => [],
    ], 'repository' => ['full_name' => 'acme/shop']]);

    return test()->call('POST', '/api/webhooks/hook-turn', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_EVENT_KEY' => $event,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
    ], $body);
}

function robin(): array
{
    return ['display_name' => 'Robin Reviewer', 'account_id' => 'id-robin', 'nickname' => 'robin'];
}

it('follows whose turn a pull request is through its reviewers and its commits', function () {
    deliverTurn()->assertOk();

    $record = OpenPullRequest::query()->sole();
    expect($record)->destination_branch->toBe('develop')
        ->and($record->opened_at->toDateString())->toBe('2026-11-12')
        ->and(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::REVIEWERS)->action->toBe('Review')
        ->and(PullRequestTurn::of($record)->who())->toBe('Robin Reviewer');

    // Robin asks for changes: the author's turn.
    $this->travel(1)->hours();
    deliverTurn(event: 'pullrequest:changes_request_created', extra: ['changes_request' => ['date' => now()->toIso8601String(), 'user' => robin()]])->assertOk();
    $record->refresh();
    expect(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::AUTHOR)->action->toBe('Address the requested changes')
        ->and(PullRequestTurn::of($record)->who())->toBe('Alex Author');

    // New commits: Robin's to look at again.
    $this->travel(1)->hours();
    deliverTurn(['source' => ['branch' => ['name' => 'DEV-5007'], 'commit' => ['hash' => str_repeat('c', 12)]], 'updated_on' => '2026-11-19T12:00:00+00:00',
        'participants' => [['user' => robin(), 'role' => 'REVIEWER', 'approved' => false, 'state' => 'changes_requested', 'participated_on' => '2026-11-19T11:00:00+00:00']]])->assertOk();
    $record->refresh();
    expect(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::REVIEWERS)->action->toBe('Review again');

    // He approves: the author merges.
    $this->travel(1)->hours();
    deliverTurn(event: 'pullrequest:approved', extra: ['approval' => ['date' => now()->toIso8601String(), 'user' => robin()]])->assertOk();
    $record->refresh();
    expect(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::AUTHOR)->action->toBe('Merge')
        ->and(array_column($record->history, 'text'))->toBe([
            'Opened by Alex Author',
            'Robin Reviewer is asked to review',
            'Robin Reviewer asked for changes',
            'New commits, up to ccccccc',
            'Robin Reviewer approved',
        ]);

    // More commits after the approval: it is of older code.
    $this->travel(1)->hours();
    deliverTurn(['source' => ['branch' => ['name' => 'DEV-5007'], 'commit' => ['hash' => str_repeat('d', 12)]], 'updated_on' => '2026-11-19T14:00:00+00:00',
        'participants' => [['user' => robin(), 'role' => 'REVIEWER', 'approved' => true, 'state' => 'approved', 'participated_on' => '2026-11-19T13:00:00+00:00']]])->assertOk();
    expect(PullRequestTurn::of($record->refresh()))->role->toBe(PullRequestTurn::REVIEWERS)->action->toBe('Review again')
        ->reason->toContain('before the newest commits');

    // An approval starts no review.
    Queue::assertNotPushed(RunContainerReview::class);
});

it('says nobody is asked yet, and that it is the AI\'s turn while it reviews', function () {
    deliverTurn(['reviewers' => []])->assertOk();
    $record = OpenPullRequest::query()->sole();

    expect(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::NOBODY)->action->toBe('Assign a reviewer');

    ReviewRun::query()->forceCreate([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'DEV-5007', 'pull_request_id' => 7, 'commit' => str_repeat('b', 40), 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    expect(PullRequestTurn::of($record))->role->toBe(PullRequestTurn::AI);
});

it('reads GitHub\'s reviews, which list only who has yet to review', function () {
    $this->repository->integration->update(['driver' => 'github']);
    $deliver = function (string $event, array $payload): TestResponse {
        $body = json_encode($payload + ['repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']]]);

        return test()->call('POST', '/api/webhooks/hook-turn', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
        ], $body);
    };
    $pull = fn (array $requested): array => [
        'number' => 7, 'title' => 'Export', 'state' => 'open', 'draft' => false, 'created_at' => '2026-11-12T09:00:00Z', 'updated_at' => '2026-11-19T10:00:00Z',
        'user' => ['login' => 'alex', 'id' => 1],
        'head' => ['ref' => 'export', 'sha' => str_repeat('b', 40), 'repo' => ['full_name' => 'acme/shop']],
        'base' => ['ref' => 'main', 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'acme/shop']],
        'requested_reviewers' => $requested,
    ];

    $deliver('pull_request', ['action' => 'review_requested', 'pull_request' => $pull([['login' => 'nora', 'id' => 5], ['login' => 'piet', 'id' => 6]])])->assertOk();
    $deliver('pull_request_review', ['action' => 'submitted', 'review' => ['state' => 'approved', 'user' => ['login' => 'nora', 'id' => 5], 'submitted_at' => '2026-11-19T11:00:00Z'],
        'pull_request' => $pull([['login' => 'piet', 'id' => 6]])])->assertOk();

    $record = OpenPullRequest::query()->sole();

    expect(collect($record->reviewers)->pluck('state', 'nickname')->sortKeys()->all())->toBe(['nora' => 'approved', 'piet' => null])
        ->and(PullRequestTurn::of($record)->who())->toBe('piet');
});

it('lists the pull requests whose turn is the user\'s under "With me", with the details', function () {
    deliverTurn()->assertOk();
    $other = OpenPullRequest::query()->forceCreate(['repository_id' => $this->repository->id, 'pull_request_id' => 8, 'title' => 'Someone else\'s', 'author' => 'Nora']);

    $user = User::factory()->create(['name' => 'Sam', 'source_accounts' => ['Robin']]);
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    $record = OpenPullRequest::query()->where('pull_request_id', 7)->sole();

    livewire(ListPullRequests::class)
        ->assertSee('Robin Reviewer')
        ->set('activeTab', 'mine')
        ->assertCanSeeTableRecords([$record])
        ->assertCanNotSeeTableRecords([$other])
        ->mountTableAction('details', $record)
        ->assertSuccessful();

    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('Whose turn')
        ->toContain('Robin Reviewer')
        ->toContain('Opened by Alex Author')
        ->toContain('develop');
});

it('links the ticket the newest review read, and only a web address', function () {
    deliverTurn()->assertOk();
    $record = OpenPullRequest::query()->sole();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    $review = fn (string $commit, array $ticket) => ReviewRun::query()->forceCreate([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'DEV-5007', 'pull_request_id' => 7, 'commit' => str_repeat($commit, 40), 'status' => ReviewRun::STATUS_FINISHED,
        'started_at' => now(), 'context' => ['ticket' => $ticket],
    ]);

    $review('a', ['reference' => 'DEV-5006', 'url' => 'https://acme.atlassian.net/browse/DEV-5006']);
    $this->travel(1)->minute();
    $review('b', ['reference' => 'DEV-5007', 'url' => 'https://acme.atlassian.net/browse/DEV-5007']);

    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('href="https://acme.atlassian.net/browse/DEV-5007"')
        ->not->toContain('DEV-5006');

    $review('c', ['reference' => 'DEV-5008', 'url' => 'javascript:alert(1)']);

    expect(view('filament.app.pull-request', PullRequestResource::details($record->refresh()))->render())
        ->toContain('DEV-5008')
        ->not->toContain('javascript:');
});

it('has the AI say whose turn it is, for the state it was written for', function () {
    config(['review.turn.notes' => true]);
    Connection::factory()->create([
        'tenant_id' => $this->repository->tenant_id, 'kind' => ConnectionKind::Llm, 'driver' => 'anthropic', 'name' => 'Anthropic',
        'credentials' => ['api_key' => 'sk-ant-test'], 'settings' => null, 'webhook_key' => null, 'webhook_secret' => null,
    ]);

    deliverTurn()->assertOk();
    Queue::assertPushed(DescribePullRequest::class);

    Describer::fake([['next_step' => 'Review: Alex asks for a first look at the export.', 'summary' => 'What changes. An order export.']]);
    $record = OpenPullRequest::query()->sole();
    (new DescribePullRequest($record->id))->handle();

    expect(PullRequestTurn::of($record->refresh())->note($record))->toBe([
        'next_step' => 'Review: Alex asks for a first look at the export.',
        'summary' => 'What changes. An order export.',
    ]);

    // Once Robin approved, the note is of an older state, and the rules speak.
    deliverTurn(event: 'pullrequest:approved', extra: ['approval' => ['date' => now()->toIso8601String(), 'user' => robin()]])->assertOk();
    expect(PullRequestTurn::of($record->refresh())->note($record))->toBeNull();
});

it('shows the ticket the title names before any review read it, and reads it when asked', function () {
    deliverTurn()->assertOk();
    $record = OpenPullRequest::query()->sole();
    $jira = Connection::factory()->jira()->create(['tenant_id' => $this->repository->tenant_id]);
    TicketSource::query()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $jira->id, 'name' => 'Jira', 'style' => TicketStyle::Key, 'prefixes' => ['DEV'], 'position' => 1]);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    // The setup's catch-all would answer the tracker first.
    Http::swap(new HttpFactory(app('events')));
    Http::fake([
        'acme.atlassian.net/rest/api/2/issue/DEV-5007/comment*' => Http::response(['comments' => [], 'total' => 0]),
        'acme.atlassian.net/rest/api/2/issue/DEV-5007*' => Http::response(['key' => 'DEV-5007', 'fields' => ['summary' => 'Fix the export', 'description' => '**Bold** <script>alert(1)</script> [link](javascript:alert(1))']]),
    ]);

    // Named by the title, not read: opening the details asks nothing of the tracker.
    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('DEV-5007')
        ->toContain('not read yet');
    Http::assertNothingSent();

    // The button in the details reads it.
    livewire(ListPullRequests::class)
        ->callAction([TestAction::make('details')->table($record), TestAction::make('readTicket')])
        ->assertNotified('DEV-5007 is read from Jira');

    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('href="https://acme.atlassian.net/browse/DEV-5007"')
        ->toContain('Fix the export')
        // Its markdown, without the tracker's HTML or a link that is not a web address.
        ->toContain('<strong>Bold</strong>')
        ->not->toContain('<script>')
        ->not->toContain('javascript:')
        ->not->toContain('not read yet');

    // A source of the repository's own, set up since, reads that reference elsewhere.
    $other = Connection::factory()->jira()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => ['site' => 'other.atlassian.net', 'email' => 'dev@example.com']]);
    $source = TicketSource::query()->create(['tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'connection_id' => $other->id, 'name' => 'Other Jira', 'style' => TicketStyle::Key, 'prefixes' => ['DEV'], 'position' => 1]);

    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('Other Jira')
        ->not->toContain('browse/DEV-5007');
    $source->delete();

    // Renamed for another ticket: what was read for the first one is not shown for it.
    $record->forceFill(['title' => 'DEV-5008 Something else'])->save();

    expect(view('filament.app.pull-request', PullRequestResource::details($record))->render())
        ->toContain('DEV-5008')
        ->not->toContain('browse/DEV-5007')
        ->not->toContain('Fix the export');
});

it('reads each row and "With me" from the pull request\'s newest review, in one query for them all', function () {
    deliverTurn()->assertOk();
    OpenPullRequest::query()->forceCreate(['repository_id' => $this->repository->id, 'pull_request_id' => 8, 'title' => 'Someone else\'s', 'author' => 'Nora']);
    $review = fn (string $status, ?string $result) => ReviewRun::query()->forceCreate([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'DEV-5007', 'pull_request_id' => 7, 'commit' => str_repeat('b', 40), 'status' => $status, 'result' => $result, 'started_at' => now(),
    ]);

    // A review that was still running, and the newer one that approved.
    $review(ReviewRun::STATUS_RUNNING, null);
    $this->travel(1)->minute();
    $review(ReviewRun::STATUS_FINISHED, 'PASSED');

    $user = User::factory()->create(['name' => 'Sam', 'source_accounts' => ['Robin']]);
    $user->tenants()->attach($this->repository->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    // Queries that read runs themselves, not the list's own query with its subqueries.
    $runQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$runQueries): void {
        $runQueries += preg_match('/^select (\* from "review_runs"|"review_runs"\.)/', $query->sql);
    });

    $list = livewire(ListPullRequests::class)
        ->assertCanSeeTableRecords(OpenPullRequest::query()->get())
        ->assertSee('Waiting for a review from Robin Reviewer')
        ->assertSee('Approved')
        ->assertDontSee('AI review running')
        ->assertDontSee('Reviewing…');

    // One for the "With me" badge, one for the rows.
    expect($runQueries)->toBe(2);

    $list->set('activeTab', 'mine')
        ->assertCanSeeTableRecords([OpenPullRequest::query()->where('pull_request_id', 7)->sole()]);
});

it('takes the review created last of two that started in the same second, in the list as in the details', function () {
    deliverTurn()->assertOk();
    $record = OpenPullRequest::query()->where('pull_request_id', 7)->sole();
    $review = fn (int $id, string $status, ?string $result) => ReviewRun::query()->forceCreate([
        'id' => $id, 'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'DEV-5007', 'pull_request_id' => 7, 'commit' => str_repeat('b', 40), 'status' => $status, 'result' => $result, 'started_at' => now()->startOfSecond(),
    ]);

    // Saved out of id order.
    $last = $review(100, ReviewRun::STATUS_FINISHED, 'PASSED');
    $review(50, ReviewRun::STATUS_RUNNING, null);

    $listed = (new ReflectionMethod(PullRequestResource::class, 'latestRuns'))->invoke(null, [$record->id]);

    expect($record->latestRun()->id)->toBe($last->id)
        ->and($listed->first()->id)->toBe($last->id);
});
