<?php

use App\Mcp\Servers\AgentServer;
use App\Mcp\Tools\CreatePullRequestTool;
use App\Mcp\Tools\UpdatePullRequestTool;
use App\Models\Connection;
use App\Models\PersonalConnection;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.bitbucket.client_id' => 'consumer-key', 'services.bitbucket.client_secret' => 'consumer-secret']);

    $this->repository = Repository::factory()->create(['workspace' => 'acme', 'slug' => 'shop']);
    $this->member = User::factory()->create(['name' => 'Sam Sample']);
    $this->member->tenants()->attach($this->repository->tenant);
    $this->connection = PersonalConnection::query()->create([
        'user_id' => $this->member->id, 'host' => 'bitbucket', 'account_id' => 'id-sam', 'nickname' => 'sam',
        'access_token' => 'sam-token', 'expires_at' => now()->addHour(), 'scopes' => ['account', 'pullrequest:write'],
    ]);

    // The pull requests on Bitbucket, by number.
    $this->onHost = [];
    Http::fake([
        'api.bitbucket.org/2.0/user*' => Http::response(['account_id' => 'id-sam']),
        'api.bitbucket.org/2.0/repositories/acme/shop/effective-default-reviewers*' => Http::response(['values' => [
            ['user' => ['uuid' => '{sam}', 'account_id' => 'id-sam']],
            ['user' => ['uuid' => '{nora}', 'account_id' => 'id-nora']],
        ]]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'POST' => Http::response(['id' => 71, 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/shop/pull-requests/71']]], 201),
                $request->method() === 'PUT' => Http::response([]),
                preg_match('#/pullrequests/(\d+)$#', $path, $id) === 1 => isset($this->onHost[$id[1]]) ? Http::response($this->onHost[$id[1]]) : Http::response([], 404),
                default => Http::response(['values' => array_values(array_filter($this->onHost, fn (array $pullRequest): bool => str_contains((string) $request['q'], "\"{$pullRequest['source']['branch']['name']}\"")))]),
            };
        },
    ]);

    $this->create = fn (array $arguments = []) => AgentServer::actingAs($this->member)->tool(CreatePullRequestTool::class, $arguments + [
        'repository' => 'acme/shop', 'branch' => 'feature/export', 'title' => 'SHOP-7 Export the orders', 'description' => 'Adds the export.',
    ]);
    $this->update = fn (array $arguments = []) => AgentServer::actingAs($this->member)->tool(UpdatePullRequestTool::class, $arguments + [
        'repository' => 'acme/shop', 'pull_request_id' => 70, 'description' => 'Exports every order.',
    ]);
    $this->pullRequest = fn (int $id, string $author, string $description = 'Old text.') => [
        'id' => $id, 'title' => 'SHOP-7 Export', 'description' => $description, 'state' => 'OPEN',
        'source' => ['branch' => ['name' => 'feature/export'], 'repository' => ['full_name' => 'acme/shop']],
        'author' => ['account_id' => $author], 'links' => ['html' => ['href' => "https://bitbucket.org/acme/shop/pull-requests/{$id}"]],
    ];
});

it('opens a pull request as the user, with the default reviewers but them, and not a second one for the branch', function () {
    ($this->create)(['destination' => 'develop', 'draft' => true])
        ->assertOk()
        ->assertStructuredContentPaths(['opened' => true, 'pull_request_id' => 71, 'url' => 'https://bitbucket.org/acme/shop/pull-requests/71']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer sam-token')
        && $request['title'] === 'SHOP-7 Export the orders'
        && $request['description'] === 'Adds the export.'
        && $request['source'] === ['branch' => ['name' => 'feature/export']]
        && $request['destination'] === ['branch' => ['name' => 'develop']]
        && $request['draft'] === true
        && $request['reviewers'] === [['uuid' => '{nora}']]);

    $this->onHost[71] = ($this->pullRequest)(71, 'id-sam');

    ($this->create)()->assertHasErrors(['feature/export already has an open pull request: https://bitbucket.org/acme/shop/pull-requests/71']);
});

it('says how to connect where the user cannot open one as themselves', function () {
    $this->connection->update(['scopes' => ['account', 'pullrequest']]);

    ($this->create)()->assertHasErrors(['connected without the right to open or edit pull requests', '"Pull requests: Write"']);

    $this->connection->delete();

    ($this->create)()->assertHasErrors(['Your Bitbucket account is not connected.', url("app/{$this->repository->tenant->slug}/profile")]);

    // GitHub reached with a token of the team's own: the user's GitHub account is not reached through the app there.
    $token = Repository::factory()->for(Connection::factory()->state(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github']), 'integration')
        ->create(['tenant_id' => $this->repository->tenant_id, 'workspace' => 'acme', 'slug' => 'site']);

    ($this->create)(['repository' => 'acme/site', 'connection' => $token->connection_id])->assertHasErrors(['and on GitHub through the GitHub App']);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('rewrites the user\'s own pull request, keeping the review\'s section, and nobody else\'s', function () {
    $block = "[//]: # (ai-review-start)\n## AI review\nAll good.\n[//]: # (ai-review-end)";
    $this->onHost[70] = ($this->pullRequest)(70, 'id-sam', "Old text.\n\n---\n\n{$block}");
    $this->onHost[69] = ($this->pullRequest)(69, 'id-nora');

    // The agent copied the review's section along: it is there once, as it was.
    ($this->update)(['description' => "Exports every order.\n\n{$block}"])->assertOk()->assertStructuredContentPath('pull_request_id', 70);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/pullrequests/70')
        && $request->hasHeader('Authorization', 'Bearer sam-token')
        && $request['title'] === 'SHOP-7 Export'
        && $request['description'] === "Exports every order.\n\n---\n\n{$block}");

    ($this->update)(['pull_request_id' => 69])->assertHasErrors(['Pull request #69 is not yours']);
    ($this->update)(['pull_request_id' => 68])->assertHasErrors(['acme/shop has no pull request #68']);

    // Only the user's own was changed.
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount(1);
});

it('opens one on GitHub through the GitHub App, into the default branch', function () {
    config(['services.github_app' => ['id' => '4242', 'slug' => 'review-central', 'client_id' => 'Iv1.client', 'client_secret' => 'client-secret', 'private_key' => 'a key', 'webhook_secret' => null]]);
    $app = Repository::factory()->for(Connection::factory()->state(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'acme', 'installation_id' => '77']]), 'integration')
        ->create(['tenant_id' => $this->repository->tenant_id, 'workspace' => 'acme', 'slug' => 'site']);
    PersonalConnection::query()->create(['user_id' => $this->member->id, 'host' => 'github', 'account_id' => '5150', 'nickname' => 'sam-gh', 'access_token' => 'ghu-token', 'expires_at' => now()->addHours(8), 'scopes' => []]);
    Http::fake([
        'api.github.com/repos/acme/site/pulls?*' => Http::response([]),
        'api.github.com/repos/acme/site/pulls' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/site/pull/12'], 201),
        'api.github.com/repos/acme/site' => Http::response(['default_branch' => 'trunk']),
    ]);

    AgentServer::actingAs($this->member)->tool(CreatePullRequestTool::class, ['repository' => 'acme/site', 'connection' => $app->connection_id, 'branch' => 'feature/export', 'title' => 'SITE-1 Export', 'description' => 'Adds it.'])
        ->assertOk()
        ->assertStructuredContentPaths(['pull_request_id' => 12, 'url' => 'https://github.com/acme/site/pull/12']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/acme/site/pulls'
        && $request->hasHeader('Authorization', 'Bearer ghu-token')
        && $request['head'] === 'feature/export'
        && $request['base'] === 'trunk'
        && $request['body'] === 'Adds it.');
});
