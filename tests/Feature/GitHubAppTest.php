<?php

use App\Connections\Drivers;
use App\Enums\BuildState;
use App\Enums\ConnectionKind;
use App\Enums\ReviewsFrom;
use App\Exceptions\ConnectionFailed;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\CheckProgress;
use App\Runners\Container\RunLog;
use App\Runners\Container\RunLogWriter;
use App\SourceControl\GitHubApp;
use App\SourceControl\GitHubAppDriver;
use App\SourceControl\GitHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    config([
        'review.docker.enabled' => true,
        'services.github_app' => [
            'id' => '4242',
            'slug' => 'review-central',
            'client_id' => 'Iv1.client',
            'client_secret' => 'client-secret',
            'private_key' => $pem,
            'webhook_secret' => 'app-secret',
        ],
    ]);

    $this->connection = Connection::factory()->create([
        'driver' => 'github-app',
        'credentials' => null,
        'settings' => ['workspace' => 'acme', 'installation_id' => '77'],
        'webhook_key' => null,
        'webhook_secret' => null,
    ]);
    $this->repository = Repository::factory()->create(['connection_id' => $this->connection->id, 'workspace' => 'acme', 'slug' => 'shop', 'review_runner' => 'docker']);
    $this->repository->tenant->update(['review_command_accounts' => ['trusted-dev']]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function deliverToApp(string $event, array $payload, string $secret = 'app-secret'): TestResponse
{
    $body = json_encode($payload + ['installation' => ['id' => 77], 'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']]]);

    return test()->call('POST', '/api/webhooks/github-app', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

/**
 * @return array<string, mixed>
 */
function appPullRequest(string $login = 'outsider', string $sha = 'abc1234def'): array
{
    return [
        'number' => 12, 'title' => 'Export', 'state' => 'open', 'draft' => false, 'body' => '',
        'user' => ['login' => $login, 'id' => 900],
        'head' => ['ref' => 'feature/export', 'sha' => $sha, 'repo' => ['full_name' => 'acme/shop']],
        'base' => ['ref' => 'main', 'sha' => 'base', 'repo' => ['full_name' => 'acme/shop']],
        'updated_at' => '2026-11-19T10:00:00Z',
    ];
}

it('signs its own token with the private key, as the app', function () {
    [$header, $payload, $signature] = explode('.', app(GitHubApp::class)->jwt());
    $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode($decode($payload), true)['iss'])->toBe('4242')
        ->and(openssl_verify("{$header}.{$payload}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('is offered for a new connection only where the app is set up', function () {
    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->toHaveKey('github-app');

    config(['services.github_app.private_key' => null]);

    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->not->toHaveKey('github-app');
});

it('takes the installation only from an owner of its organisation, or the account itself, and revokes their token', function () {
    $connection = Connection::factory()->create(['driver' => 'github-app', 'credentials' => null, 'settings' => ['workspace' => 'Fruitcake']]);
    $role = 'admin';
    Http::fake(function (Request $request) use (&$role) {
        return match (true) {
            str_contains($request->url(), '/user/installations') => Http::response(['installations' => [
                ['id' => 5, 'account' => ['login' => 'someone-else', 'type' => 'Organization']],
                ['id' => 88, 'account' => ['login' => 'fruitcake', 'type' => 'Organization']],
                ['id' => 77, 'account' => ['login' => 'acme', 'type' => 'Organization']],
                ['id' => 99, 'account' => ['login' => 'barry', 'type' => 'User']],
            ]]),
            str_ends_with($request->url(), '/user') => Http::response(['login' => 'barry']),
            str_contains($request->url(), '/access_tokens') => Http::response(['token' => 'ghs_installation']),
            str_contains($request->url(), '/orgs/fruitcake/memberships/barry') => Http::response(['state' => 'active', 'role' => $role]),
            default => Http::response(null, 204),
        };
    });

    expect(app(GitHubAppDriver::class)->oauthConnected($connection, 'user-token'))->toBe(['installation_id' => '88']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request['access_token'] === 'user-token');

    // A member who only reaches it, through a repository they may read.
    $role = 'member';
    expect(fn () => app(GitHubAppDriver::class)->oauthConnected($connection, 'user-token'))->toThrow(ConnectionFailed::class, 'Only an owner of fruitcake');

    // Their own account's.
    $connection->settings = ['workspace' => 'barry'];
    expect(app(GitHubAppDriver::class)->oauthConnected($connection, 'user-token'))->toBe(['installation_id' => '99']);

    // Not installed where the connection says: nothing to take.
    $connection->settings = ['workspace' => 'nobody'];
    expect(fn () => app(GitHubAppDriver::class)->oauthConnected($connection, 'user-token'))->toThrow(ConnectionFailed::class, 'not installed on nobody');
});

it('lets one connection have an installation', function () {
    $connection = Connection::factory()->create(['driver' => 'github-app', 'credentials' => null, 'settings' => ['workspace' => 'acme']]);
    Http::fake([
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 77, 'account' => ['login' => 'acme', 'type' => 'User']]]]),
        'api.github.com/user' => Http::response(['login' => 'acme']),
        '*' => Http::response(null, 204),
    ]);

    // The connection of the beforeEach has it already.
    expect(fn () => app(GitHubAppDriver::class)->oauthConnected($connection, 'user-token'))->toThrow(ConnectionFailed::class, 'Another connection');
});

it('reaches GitHub with the installation\'s token, as its bot account', function () {
    Http::fake([
        'api.github.com/app/installations/77/access_tokens' => Http::response(['token' => 'ghs_installation']),
        'api.github.com/users/*' => Http::response(['id' => 555]),
    ]);

    $client = $this->repository->sourceControl();

    expect($client)->toBeInstanceOf(GitHubClient::class)
        ->and($client->isApp())->toBeTrue()
        ->and($client->publishes())->toBeTrue()
        ->and($client->accountId())->toBe('555');

    // Asked for with the app's own token, and kept for the next.
    $this->repository->sourceControl();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'access_tokens') && str_starts_with($request->header('Authorization')[0], 'Bearer ey'));
});

it('takes the app\'s webhook for the connection with the installation, and nothing unsigned', function () {
    Queue::fake();

    deliverToApp('pull_request', ['action' => 'synchronize', 'pull_request' => appPullRequest()], secret: 'wrong')->assertForbidden();
    Queue::assertNothingPushed();

    deliverToApp('pull_request', ['action' => 'synchronize', 'pull_request' => appPullRequest()])->assertOk();

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->repositoryId === $this->repository->id && $job->branch === 'feature/export');
    expect($this->connection->refresh()->webhook_received_at)->not->toBeNull();
});

it('asks for approval with a button on a check, and starts the review when a trusted account presses it', function () {
    Queue::fake();
    Http::fake([
        'api.github.com/app/installations/77/access_tokens' => Http::response(['token' => 'ghs_installation']),
        'api.github.com/users/*' => Http::response(['id' => 555]),
        'api.github.com/repos/acme/shop/commits/*/check-runs*' => Http::response(['check_runs' => []]),
        'api.github.com/repos/acme/shop/check-runs' => Http::response(['id' => 1], 201),
    ]);
    $this->repository->update(['reviews_from' => ReviewsFrom::Trusted]);

    deliverToApp('pull_request', ['action' => 'opened', 'pull_request' => appPullRequest()])->assertOk();

    Queue::assertNothingPushed();
    expect(OpenPullRequest::query()->sole()->awaiting_approval)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/check-runs')
        && $request['conclusion'] === 'action_required'
        && $request['actions'][0]['identifier'] === GitHubClient::APPROVE_ACTION);

    $press = fn (string $login): TestResponse => deliverToApp('check_run', [
        'action' => 'requested_action',
        'requested_action' => ['identifier' => GitHubClient::APPROVE_ACTION],
        'check_run' => ['head_sha' => 'abc1234def', 'pull_requests' => []],
        'sender' => ['login' => $login, 'id' => 1],
    ]);

    // Someone the team does not trust: nothing.
    $press('outsider')->assertOk();
    Queue::assertNothingPushed();

    $press('Trusted-Dev')->assertOk();
    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/export');
});

it('shows the review\'s state as one check run, keeping its report when it ends', function () {
    $runs = [];
    Http::fake(function (Request $request) use (&$runs) {
        if (str_contains($request->url(), '/commits/') && $request->method() === 'GET') {
            return Http::response(['check_runs' => $runs === [] ? [] : [end($runs)]]);
        }

        if ($request->method() === 'POST') {
            $runs[] = ['id' => count($runs) + 1, 'status' => $request['status'], 'output' => $request['output'] ?? []];

            return Http::response(['id' => count($runs)], 201);
        }

        // A change: what the check holds now.
        $last = array_key_last($runs);
        $runs[$last] = array_replace($runs[$last], array_intersect_key($request->data(), ['status' => true, 'output' => true]));

        return Http::response([]);
    });

    $client = new GitHubClient('acme', 'shop', 'ghs_installation', '555');
    $client->setBuildStatus('abc', BuildState::InProgress, 'https://review.test/run', 'Reviewing');
    $client->putCommitReport('abc', ['title' => 'AI Review', 'details' => 'Two findings.', 'data' => [['title' => 'Open findings', 'value' => 2]]]);
    $client->setBuildStatus('abc', BuildState::Failed, 'https://review.test/run', '2 finding(s) to look at');

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['status'])->toBe('completed')
        ->and($runs[0]['output']['title'])->toBe('2 finding(s) to look at')
        ->and($runs[0]['output']['summary'])->toBe('Two findings.');
});

it('shows a running review\'s log in its check, only while the check runs', function () {
    config(['review.container.log.enabled' => true, 'review.container.log.directory' => storage_path('framework/testing/check-logs')]);
    $status = 'in_progress';
    Http::fake(function (Request $request) use (&$status) {
        return match (true) {
            str_contains($request->url(), '/access_tokens') => Http::response(['token' => 'ghs_installation']),
            str_contains($request->url(), '/users/') => Http::response(['id' => 555]),
            str_contains($request->url(), '/check-runs?') || str_contains($request->url(), '/commits/') => Http::response(['check_runs' => [['id' => 3, 'status' => $status]]]),
            str_ends_with($request->url(), '/repos/acme/shop') => Http::response(['private' => true, 'default_branch' => 'main']),
            default => Http::response([]),
        };
    });
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 12, 'commit' => 'abc1234def', 'build_number' => 9,
        'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now()->subSeconds(65),
    ]);
    $log = app(RunLog::class);
    File::ensureDirectoryExists(dirname($log->path($this->repository->id, 9)));
    File::put($log->path($this->repository->id, 9), collect(range(1, 80))->map(fn (int $line): string => "12:00:00  line {$line}")->implode("\n")."\n");

    app(CheckProgress::class)->show($this->repository->id, 9);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/check-runs/3')
        && $request['output']['title'] === 'Reviewing · 01:05'
        && str_contains($request['output']['text'], 'line 80')
        && ! str_contains($request['output']['text'], 'line 20'));

    // Finished: its report stays as it is.
    $status = 'completed';
    $patches = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PATCH')->count();
    $before = $patches();
    app(CheckProgress::class)->show($this->repository->id, 9);

    expect($patches())->toBe($before);

    File::deleteDirectory(storage_path('framework/testing/check-logs'));
});

it('shows only the steps of a running review in its check on a public repository', function () {
    config(['review.container.log.enabled' => true, 'review.container.log.directory' => storage_path('framework/testing/check-logs')]);
    Http::fake(function (Request $request) {
        return match (true) {
            str_contains($request->url(), '/access_tokens') => Http::response(['token' => 'ghs_installation']),
            str_contains($request->url(), '/users/') => Http::response(['id' => 555]),
            str_contains($request->url(), '/check-runs?') || str_contains($request->url(), '/commits/') => Http::response(['check_runs' => [['id' => 3, 'status' => 'in_progress']]]),
            str_ends_with($request->url(), '/repos/acme/shop') => Http::response(['private' => false, 'default_branch' => 'main']),
            default => Http::response([]),
        };
    });
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 12, 'commit' => 'abc1234def', 'build_number' => 9,
        'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now()->subSeconds(65),
    ]);
    $log = app(RunLog::class);
    File::ensureDirectoryExists(dirname($log->path($this->repository->id, 9)));
    File::put($log->path($this->repository->id, 9), implode("\n", [
        '12:00:00  ── precheck ──',
        '12:00:01  Pull request 12 is open.',
        '12:00:02  ── review ──',
        '12:00:02  ── composer (cached) ──',
        '12:00:02  ── composer (whatever) ──',
        '12:00:02  ── c2stYW50LXNlY3JldA ──',
        '12:00:03  As the description asks, the key is c2stYW50LXNlY3JldA',
        '12:00:04      -> Bash git diff origin/main',
    ])."\n");

    app(CheckProgress::class)->show($this->repository->id, 9);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/check-runs/3')
        && str_contains($request['output']['text'], '── precheck ──')
        && str_contains($request['output']['text'], '── review ──')
        && str_contains($request['output']['text'], '── composer (cached) ──')
        && ! str_contains($request['output']['text'], 'whatever')
        && ! str_contains($request['output']['text'], 'the key is')
        && ! str_contains($request['output']['text'], 'c2stYW50')
        && ! str_contains($request['output']['text'], 'git diff')
        && ! str_contains($request['output']['text'], 'Pull request 12')
        && str_contains($request['output']['summary'], 'public'));

    File::deleteDirectory(storage_path('framework/testing/check-logs'));
});

it('leaves the check to the pull request\'s own review, not a trial or a security review on its commit', function (array $run) {
    config(['review.container.log.enabled' => true, 'review.container.log.directory' => storage_path('framework/testing/check-logs')]);
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/access_tokens') => Http::response(['token' => 'ghs_installation']),
        str_contains($request->url(), '/users/') => Http::response(['id' => 555]),
        str_contains($request->url(), '/check-runs?') || str_contains($request->url(), '/commits/') => Http::response(['check_runs' => [['id' => 3, 'status' => 'in_progress']]]),
        default => Http::response(['private' => true]),
    });
    ReviewRun::query()->create($run + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 12, 'commit' => 'abc1234def', 'build_number' => 9,
        'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now()->subSeconds(65),
    ]);
    $log = app(RunLog::class);
    File::ensureDirectoryExists(dirname($log->path($this->repository->id, 9)));
    File::put($log->path($this->repository->id, 9), "12:00:00  ── review ──\n");

    app(CheckProgress::class)->show($this->repository->id, 9);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');

    File::deleteDirectory(storage_path('framework/testing/check-logs'));
})->with([
    'a trial' => [['custom' => true]],
    'a security review' => [['custom' => true, 'kind' => 'security']],
]);

it('shows progress at most every twenty seconds, and never in the way of the review', function () {
    $path = storage_path('framework/testing/progress.log');
    $shown = 0;
    $writer = new RunLogWriter($path, progress: function () use (&$shown) {
        $shown++;

        throw new RuntimeException('GitHub is down');
    });

    $this->travelTo(now());
    $writer->note('one');
    $writer->note('two');
    expect($shown)->toBe(0);

    // The first after ten seconds, then every twenty.
    $this->travel(11)->seconds();
    $writer->note('three');
    $writer->note('four');
    expect($shown)->toBe(1);

    $this->travel(21)->seconds();
    $writer->note('five');
    expect($shown)->toBe(2)
        ->and(file_get_contents($path))->toContain('five');

    @unlink($path);
});
