<?php

use App\Connections\Drivers;
use App\Enums\ConnectionKind;
use App\Exceptions\CheckoutFailed;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewTrialRefused;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Runners\Container\Checkout;
use App\Services\ReviewTrial;
use App\SourceControl\GitClient;
use App\SourceControl\GitHubClient;
use App\SourceControl\PullRequestLink;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['review.docker.enabled' => true]);
    $this->tenant = Tenant::factory()->create();

    /**
     * A pull request as GitHub describes it.
     *
     * @param  array<string, mixed>  $more
     */
    $this->pull = fn (array $more = []): array => array_replace_recursive([
        'number' => 12, 'title' => 'Add the export', 'body' => 'Adds the export.', 'state' => 'open', 'draft' => false, 'merged_at' => null,
        'merge_commit_sha' => str_repeat('c', 40), 'updated_at' => '2026-11-18T10:00:00Z', 'user' => ['login' => 'nora'],
        'head' => ['ref' => 'feature/export', 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'acme/shop']],
        'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['full_name' => 'acme/shop']],
    ], $more);
});

it('reads a pull request on GitHub in the shape the review reads one in', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/pulls/12' => Http::response(($this->pull)()),
        'api.github.com/repos/acme/shop/pulls/13' => Http::response(($this->pull)(['number' => 13, 'state' => 'closed', 'merged_at' => '2026-11-18T11:00:00Z'])),
        'api.github.com/repos/acme/shop/pulls/14' => Http::response(($this->pull)(['number' => 14, 'state' => 'closed'])),
        'api.github.com/repos/acme/shop/pulls/15' => Http::response([], 404),
        'api.github.com/repos/acme/shop/issues/12/comments*' => Http::response([['id' => 2, 'body' => 'Looks good.', 'user' => ['login' => 'piet', 'id' => 7], 'created_at' => '2026-11-18T10:05:00Z']]),
        'api.github.com/repos/acme/shop/pulls/12/comments*' => Http::response([['id' => 1, 'body' => 'Why this?', 'user' => ['login' => 'nora', 'id' => 5], 'created_at' => '2026-11-18T10:01:00Z', 'path' => 'app/Export.php', 'line' => 12]]),
    ]);
    $client = new GitHubClient('acme', 'shop', 'token-one');

    expect($client->getPullRequest('12'))->toMatchArray([
        'id' => 12, 'title' => 'Add the export', 'description' => 'Adds the export.', 'state' => 'OPEN', 'draft' => false,
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 40)]],
        'destination' => ['branch' => ['name' => 'main'], 'commit' => ['hash' => str_repeat('b', 40)]],
        // Not merged, so the commit GitHub tried the merge in is not one of its own.
        'merge_commit' => null, 'from_fork' => false,
    ])
        ->and($client->getPullRequest('13'))->toMatchArray(['state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('c', 40)]])
        ->and($client->getPullRequest('14')['state'])->toBe('DECLINED')
        ->and($client->getPullRequest('15'))->toBeNull()
        // The remarks on the pull request and on its lines as one conversation, oldest first.
        ->and(array_column($client->getPullRequestComments('12'), 'id'))->toBe([1, 2])
        ->and($client->getPullRequestComments('12')[0])->toMatchArray(['content' => ['raw' => 'Why this?'], 'inline' => ['path' => 'app/Export.php', 'to' => 12]])
        // With a token the review is posted there; without one, as anyone, it is only read.
        ->and($client->publishes())->toBeTrue()
        ->and((new GitHubClient('acme', 'shop'))->publishes())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-one') && $request->hasHeader('User-Agent', 'review-central'));
});

it('clones a public repository as anyone, and a private one with its token', function () {
    $seen = [];
    Process::fake(function ($process) use (&$seen) {
        $command = (array) $process->command;
        $seen[] = $process->environment;

        if ($command[1] === 'clone') {
            File::ensureDirectoryExists(end($command));
        }

        return Process::result(in_array('rev-parse', $command, true) ? str_repeat('a', 40)."\n" : '');
    });

    $directory = storage_path('framework/testing/review-work/hosts');
    $repository = fn (array $credentials): Repository => Repository::factory()->create([
        'workspace' => 'acme', 'slug' => 'shop',
        'connection_id' => Connection::query()->create(['tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::SourceControl, 'driver' => 'github', 'name' => 'GitHub', 'credentials' => $credentials, 'settings' => []])->id,
    ]);

    app(Checkout::class)->clone($repository([]), 'main', null, $directory);

    // No header with half a sign-in in it: GitHub would refuse that for a public repository too.
    expect($seen[0]['GIT_CONFIG_KEY_0'])->toBe('credential.helper')
        ->and(json_encode($seen))->not->toContain('Authorization');

    $seen = [];
    app(Checkout::class)->clone($repository(['access_token' => 'token-one']), 'main', null, $directory);

    expect($seen[0]['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-access-token:token-one'));

    Process::assertRan(fn ($process): bool => in_array('https://github.com/acme/shop.git', (array) $process->command, true));

    File::deleteDirectory(storage_path('framework/testing/review-work'));
});

it('tries a public pull request on GitHub with no connection of the tenant\'s own', function () {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/laravel/framework/pulls/9' => Http::response(($this->pull)(['number' => 9, 'state' => 'closed', 'merged_at' => '2026-11-18T11:00:00Z', 'head' => ['repo' => ['full_name' => 'someone/framework']], 'base' => ['repo' => ['full_name' => 'laravel/framework']]])),
        'api.github.com/repos/laravel/framework/contents/composer.json' => Http::response('{"require": {"laravel/framework": "^13.0"}}'),
        'api.github.com/*' => Http::response([], 404),
    ]);

    $repository = app(ReviewTrial::class)->start($this->tenant, 'https://github.com/laravel/framework/pull/9/files');

    // A connection for any public repository was made, and the repository says whose it is.
    $connection = $this->tenant->connections()->where('driver', 'github')->sole();

    expect($connection->only(['name', 'settings']))->toBe(['name' => 'GitHub (public repositories)', 'settings' => []])
        ->and($repository->only(['workspace', 'slug', 'reviews_enabled', 'review_stack']))->toBe(['workspace' => 'laravel', 'slug' => 'framework', 'reviews_enabled' => false, 'review_stack' => ['laravel']])
        ->and($repository->sourceControl())->toBeInstanceOf(GitHubClient::class);

    // Merged, so it is reviewed as it went in, also when it came from a fork.
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->pullRequestId === 9 && $job->trial->mergeCommit === str_repeat('c', 40));

    // The next one finds the connection that is there.
    app(ReviewTrial::class)->start($this->tenant, 'https://github.com/laravel/framework/pull/9');

    expect($this->tenant->connections()->where('driver', 'github')->count())->toBe(1);
});

it('refuses a pull request merged by rebase, whose commits went in one by one, unless from the diff alone', function () {
    Queue::fake();
    $merged = ['number' => 9, 'state' => 'closed', 'merged_at' => '2026-11-18T11:00:00Z', 'commits' => 3];
    $commit = fn (string $message, string $date): array => ['commit' => ['message' => $message, 'author' => ['date' => $date]]];
    Http::fake([
        'api.github.com/repos/acme/shop/pulls/9/commits*' => Http::response([$commit('Start', '2026-11-17T09:00:00Z'), $commit('Middle', '2026-11-17T10:00:00Z'), $commit('Finish the export', '2026-11-17T11:00:00Z')]),
        'api.github.com/repos/acme/shop/pulls/9' => Http::response(($this->pull)($merged)),
        // Its last commit, put on the branch again: the same message and author date, one parent.
        'api.github.com/repos/acme/shop/commits/'.str_repeat('c', 40) => Http::response($commit('Finish the export', '2026-11-17T11:00:00Z') + ['parents' => [['sha' => str_repeat('e', 40)]]]),
        'api.github.com/*' => Http::response([], 404),
    ]);

    expect((new GitHubClient('acme', 'shop', null))->getPullRequest('9')['merge_commit'])->toBe(['hash' => str_repeat('c', 40), 'rebased' => true])
        ->and(fn () => app(ReviewTrial::class)->start($this->tenant, 'https://github.com/acme/shop/pull/9'))
        ->toThrow(ReviewTrialRefused::class, 'merged by rebase');
});

it('takes a squashed pull request for one commit that holds it all', function () {
    $commit = fn (string $message, string $date): array => ['commit' => ['message' => $message, 'author' => ['date' => $date]]];
    Http::fake([
        'api.github.com/repos/acme/shop/pulls/9/commits*' => Http::response([$commit('Start', '2026-11-17T09:00:00Z'), $commit('Finish the export', '2026-11-17T11:00:00Z')]),
        'api.github.com/repos/acme/shop/pulls/9' => Http::response(($this->pull)(['number' => 9, 'state' => 'closed', 'merged_at' => '2026-11-18T11:00:00Z', 'commits' => 2])),
        'api.github.com/repos/acme/shop/commits/'.str_repeat('c', 40) => Http::response($commit("Add the export (#9)\n\n* Start\n* Finish the export", '2026-11-18T11:00:00Z') + ['parents' => [['sha' => str_repeat('e', 40)]]]),
    ]);

    expect((new GitHubClient('acme', 'shop', null))->getPullRequest('9')['merge_commit'])->toBe(['hash' => str_repeat('c', 40)]);
});

it('tries an open pull request from a fork on GitHub through the ref GitHub keeps it under', function () {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/laravel/framework/pulls/9' => Http::response(($this->pull)(['number' => 9, 'head' => ['repo' => ['full_name' => 'someone/framework']], 'base' => ['repo' => ['full_name' => 'laravel/framework']]])),
        'api.github.com/*' => Http::response([], 404),
    ]);

    app(ReviewTrial::class)->start($this->tenant, 'https://github.com/laravel/framework/pull/9');

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->trial?->pullRef === 'pull/9/head' && $job->trial->destination === 'main');
});

it('clones a pull request\'s head through its pull ref, on top of the destination', function () {
    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[1] === 'clone') {
            File::ensureDirectoryExists(end($command));
        }

        return Process::result(in_array('rev-parse', $command, true) ? str_repeat('f', 40)."\n" : '');
    });
    $repository = Repository::factory()->create([
        'workspace' => 'laravel', 'slug' => 'framework',
        'connection_id' => Connection::query()->create(['tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::SourceControl, 'driver' => 'github', 'name' => 'GitHub', 'credentials' => [], 'settings' => []])->id,
    ]);

    $head = app(Checkout::class)->clonePullRef($repository, 'main', 'pull/9/head', storage_path('framework/testing/review-work/fork'));

    expect($head)->toBe(str_repeat('f', 40))
        ->and(fn () => app(Checkout::class)->clonePullRef($repository, 'main', 'refs/heads/../x', storage_path('framework/testing/review-work/fork')))->toThrow(CheckoutFailed::class);

    Process::assertRan(fn ($process): bool => in_array('+refs/pull/9/head:refs/remotes/origin/pull-head', (array) $process->command, true));
    Process::assertRan(fn ($process): bool => ((array) $process->command)[1] === 'checkout' && in_array('refs/remotes/origin/pull-head', (array) $process->command, true));

    File::deleteDirectory(storage_path('framework/testing/review-work'));
});

it('knows a git server by the address its repositories are cloned from, and nothing else of it', function () {
    $client = new GitClient('https://git.example.com/acme/', 'shop', 'deploy', 'secret');
    $driver = app(Drivers::class)->get(ConnectionKind::SourceControl, 'git');
    $connection = new Connection(['settings' => ['base_url' => 'https://git.example.com/acme']]);

    expect($client->cloneAccess())->toBe(['url' => 'https://git.example.com/acme/shop.git', 'username' => 'deploy', 'password' => 'secret'])
        ->and((new GitClient('https://git.example.com/acme', 'shop'))->cloneAccess())->toBe(['url' => 'https://git.example.com/acme/shop.git', 'username' => null, 'password' => null])
        // No pull requests and nothing to post to: what there is to do is review a branch.
        ->and($client->getPullRequest('1'))->toBeNull()
        ->and($client->findOpenPullRequestByBranch('main'))->toBeNull()
        ->and($client->publishes())->toBeFalse()
        // The name is typed as it is in the address: a git server has no list to ask.
        ->and($driver->searchRepositories($connection, 'tools/shop'))->toBe(['tools/shop' => 'tools/shop'])
        ->and($driver->searchRepositories($connection, '../shop'))->toBe([])
        ->and(fn () => $driver->installWebhook($connection, 'https://review.example.com/hook'))->toThrow(ConnectionFailed::class);
});

it('reads the address of a pull request on either host', function (string $url, ?array $expected) {
    $link = PullRequestLink::parse($url);

    expect($link === null ? null : [$link->host, $link->workspace, $link->slug, $link->id])->toBe($expected);
})->with([
    ['https://bitbucket.org/acme/shop/pull-requests/570/diff', ['bitbucket', 'acme', 'shop', 570]],
    ['https://github.com/laravel/framework/pull/9', ['github', 'laravel', 'framework', 9]],
    ['https://github.com/laravel/framework/pull/9/files#diff-1', ['github', 'laravel', 'framework', 9]],
    ['https://github.com/laravel/framework/issues/9', null],
    ['https://gitlab.com/acme/shop/-/merge_requests/3', null],
]);
