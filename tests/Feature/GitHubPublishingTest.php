<?php

use App\Enums\BuildState;
use App\Exceptions\ConnectionFailed;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Runners\Container\Checkout;
use App\Services\Connections;
use App\SourceControl\GitHubClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
 * GitHub with a personal access token: the review is posted there, and its
 * webhook starts one. GitHub is faked by one callback that keeps what was posted,
 * so a test reads back what it wrote, as a second run would.
 */

beforeEach(function () {
    config(['review.docker.enabled' => true]);

    $this->connection = Connection::factory()->gitHubSource()->create(['webhook_key' => 'hook-gh', 'webhook_secret' => 'secret']);
    $this->repository = $this->reviewing(Repository::factory()->create(['connection_id' => $this->connection->id, 'workspace' => 'acme', 'slug' => 'shop']));
    $this->sha = str_repeat('a', 40);

    // What GitHub holds: comments of both kinds, reviews, threads and statuses.
    $this->github = (object) ['issueComments' => [], 'reviewComments' => [], 'reviews' => [], 'threads' => [], 'minimized' => [], 'statuses' => [], 'hooks' => [], 'orgHooks' => null, 'pull' => [], 'next' => 3_000_000_000, 'graphqlFails' => false, 'mutationsRefused' => false];

    $this->pull = fn (array $more = []): array => array_replace_recursive([
        'number' => 12, 'title' => 'Add the export', 'body' => 'Adds the export.', 'state' => 'open', 'draft' => false, 'merged_at' => null,
        'updated_at' => '2026-11-19T10:00:00Z', 'user' => ['login' => 'nora', 'id' => 5],
        'head' => ['ref' => 'feature/export', 'sha' => $this->sha, 'repo' => ['full_name' => 'acme/shop']],
        'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['full_name' => 'acme/shop']],
    ], $more);

    Http::fake(function (Request $request) {
        $g = $this->github;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $repo = '/repos/acme/shop';

        // The token belongs to the reviewer's own account.
        if ($path === '/user') {
            return Http::response(['id' => 42, 'login' => 'review-bot']);
        }

        if ($path === '/graphql') {
            $query = (string) $request['query'];

            if ($g->graphqlFails) {
                return Http::response(['errors' => [['message' => 'Resource not accessible by personal access token']]]);
            }

            // A GitHub App without Contents write: it may read the threads, not resolve them.
            if ($g->mutationsRefused && str_starts_with(trim($query), 'mutation')) {
                return Http::response(['errors' => [['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by integration']]]);
            }

            if (str_contains($query, 'resolveReviewThread') || str_contains($query, 'unresolveReviewThread')) {
                $g->threads[$request['variables']['id']]['resolved'] = ! str_contains($query, 'unresolve');

                return Http::response(['data' => []]);
            }

            if (str_contains($query, 'minimizeComment')) {
                $g->minimized[$request['variables']['id']] = ! str_contains($query, 'unminimize');

                return Http::response(['data' => []]);
            }

            return Http::response(['data' => ['repository' => ['pullRequest' => [
                'reviewThreads' => ['nodes' => collect($g->threads)->map(fn (array $thread, string $id): array => [
                    'id' => $id, 'isResolved' => $thread['resolved'], 'comments' => ['nodes' => array_map(fn (int $comment): array => ['fullDatabaseId' => (string) $comment], $thread['comments'])],
                ])->values()->all()],
                'comments' => ['nodes' => collect($g->issueComments)->map(fn (array $comment): array => [
                    'fullDatabaseId' => (string) $comment['id'], 'isMinimized' => $g->minimized["IC_{$comment['id']}"] ?? false, 'minimizedReason' => 'resolved',
                ])->values()->all()],
            ]]]]);
        }

        if ($path === '/users/acme') {
            return Http::response(['login' => 'acme', 'type' => $g->orgHooks === null ? 'User' : 'Organization']);
        }

        if (preg_match('~^/orgs/acme/hooks/(\d+)$~', $path) === 1 && $method === 'PATCH') {
            return Http::response(['id' => 1]);
        }

        if ($path === '/orgs/acme/hooks') {
            if ($g->orgHooks === false) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if ($method === 'POST') {
                $g->orgHooks[] = $request->data();
            }

            return Http::response($method === 'GET' ? $g->orgHooks : ['id' => 1]);
        }

        if (preg_match('~^/repos/acme/(\w+)/hooks$~', $path, $m) === 1) {
            if ($method === 'POST') {
                $g->hooks[$m[1]][] = $request->data();
            }

            return Http::response($method === 'GET' ? ($g->hooks[$m[1]] ?? []) : ['id' => 1]);
        }

        if ($path === "{$repo}/pulls/12" && $method === 'GET') {
            return Http::response(($this->pull)($g->pull));
        }

        if ($path === "{$repo}/pulls/12" && $method === 'PATCH') {
            $g->pull['body'] = $request['body'];

            return Http::response(($this->pull)($g->pull));
        }

        if ($path === "{$repo}/issues/12/comments") {
            if ($method === 'POST') {
                $g->issueComments[] = $comment = ['id' => $id = $g->next++, 'body' => $request['body'], 'user' => ['login' => 'review-bot', 'id' => 42], 'created_at' => now()->toIso8601String(), 'html_url' => "https://github.com/acme/shop/pull/12#issuecomment-{$id}"];

                return Http::response($comment, 201);
            }

            return Http::response($request['page'] > 1 ? [] : $g->issueComments);
        }

        if ($path === "{$repo}/pulls/12/comments") {
            if ($method === 'POST') {
                $g->reviewComments[] = $comment = ['id' => $id = $g->next++, 'body' => $request['body'], 'path' => $request['path'], 'line' => $request['line'], 'user' => ['login' => 'review-bot', 'id' => 42], 'created_at' => now()->toIso8601String(), 'html_url' => "https://github.com/acme/shop/pull/12#discussion_r{$id}"];
                $g->threads["PRRT_{$comment['id']}"] = ['resolved' => false, 'comments' => [$comment['id']]];

                return Http::response($comment, 201);
            }

            return Http::response($request['page'] > 1 ? [] : $g->reviewComments);
        }

        if (preg_match("~^{$repo}/pulls/12/comments/(\\d+)/replies$~", $path, $m) === 1) {
            $parent = collect($g->reviewComments)->firstWhere('id', (int) $m[1]);

            if ($parent === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            // As GitHub: a reply goes to a thread's first comment, not to a reply in it.
            if (isset($parent['in_reply_to_id'])) {
                return Http::response(['message' => 'Validation Failed'], 422);
            }

            $g->reviewComments[] = $comment = ['id' => $id = $g->next++, 'body' => $request['body'], 'path' => $parent['path'], 'line' => $parent['line'], 'in_reply_to_id' => $parent['id'], 'user' => ['login' => 'review-bot', 'id' => 42], 'created_at' => now()->toIso8601String(), 'html_url' => "https://github.com/acme/shop/pull/12#discussion_r{$id}"];
            $thread = collect($g->threads)->search(fn (array $thread): bool => in_array($parent['id'], $thread['comments'], true));
            $g->threads[$thread]['comments'][] = $comment['id'];

            return Http::response($comment, 201);
        }

        if (preg_match("~^{$repo}/(pulls|issues)/comments/(\\d+)$~", $path, $m) === 1) {
            $list = $m[1] === 'pulls' ? 'reviewComments' : 'issueComments';
            $index = collect($g->{$list})->search(fn (array $comment): bool => $comment['id'] === (int) $m[2]);

            if ($index === false) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            if ($method === 'PATCH') {
                $g->{$list}[$index]['body'] = $request['body'];
            }

            return Http::response($g->{$list}[$index] + ['node_id' => "IC_{$m[2]}"]);
        }

        if ($path === "{$repo}/pulls/12/reviews") {
            if ($method === 'POST') {
                $g->reviews[] = $review = ['id' => $g->next++, 'state' => ['APPROVE' => 'APPROVED', 'REQUEST_CHANGES' => 'CHANGES_REQUESTED'][$request['event']], 'user' => ['id' => 42], 'body' => $request['body'] ?? ''];

                return Http::response($review);
            }

            return Http::response($request['page'] > 1 ? [] : $g->reviews);
        }

        if (preg_match("~^{$repo}/pulls/12/reviews/(\\d+)/dismissals$~", $path, $m) === 1) {
            $index = collect($g->reviews)->search(fn (array $review): bool => $review['id'] === (int) $m[1]);
            $g->reviews[$index]['state'] = 'DISMISSED';

            return Http::response($g->reviews[$index]);
        }

        if (str_starts_with($path, "{$repo}/statuses/")) {
            $g->statuses[] = $request->data();

            return Http::response([], 201);
        }

        if (str_starts_with($path, "{$repo}/commits/")) {
            return Http::response(['sha' => $this->sha, 'commit' => ['message' => 'Add the export', 'author' => ['name' => 'Nora']]]);
        }

        return Http::response(['message' => "Not faked: {$method} {$path}"], 500);
    });

    $this->client = new GitHubClient('acme', 'shop', 'github-token');

    // A signed delivery of GitHub's.
    $this->deliver = fn (string $event, array $payload) => $this->call('POST', '/api/webhooks/hook-gh', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body = (string) json_encode($payload), 'secret'),
    ], $body);
});

it('posts a finding on its line, replies in its thread, and reads both back as one conversation', function () {
    $finding = $this->client->addPullRequestComment('12', 'The export skips the last row.', 'app/Export.php', 12);
    $this->client->addPullRequestComment('12', 'Fixed in abc.', parentId: $finding['id']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls/12/comments')
        && $request['commit_id'] === $this->sha && $request['line'] === 12 && $request['side'] === 'RIGHT');

    $comments = $this->client->getPullRequestComments('12');

    expect($comments)->toHaveCount(2)
        ->and($comments[0])->toMatchArray(['id' => $finding['id'], 'inline' => ['path' => 'app/Export.php', 'to' => 12]])
        ->and($comments[1]['parent'])->toBe(['id' => $finding['id']])
        ->and($comments[1]['user'])->toBe(['display_name' => 'review-bot', 'nickname' => 'review-bot', 'account_id' => '42']);
});

it('answers a comment on the pull request itself, which has no thread, with one that names it', function () {
    $question = $this->client->addPullRequestComment('12', 'Why is this slow?');
    $this->client->addPullRequestComment('12', 'Because it reads every row.', parentId: $question['id']);

    // On GitHub it links to the comment it answers, which has no thread to sit in.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/12/comments')
        && str_starts_with((string) $request['body'], "↪ [In reply to this comment](https://github.com/{$this->repository->workspace}/{$this->repository->slug}/pull/12#issuecomment-{$question['id']})\n\nBecause it reads every row."));

    $comments = $this->client->getPullRequestComments('12');

    expect($comments[1]['parent'])->toBe(['id' => $question['id']])
        ->and($comments[1]['content']['raw'])->toBe("Because it reads every row.\n\n[//]: # (ai-reply-to {$question['id']})")
        ->and($comments[1])->not->toHaveKey('inline');
});

it('answers a reply in a line\'s thread, which GitHub takes no reply to, with one that links to it', function () {
    $finding = $this->client->addPullRequestComment('12', 'The export skips the last row.', 'app/Export.php', 12);
    $question = $this->client->addPullRequestComment('12', '@review why?', parentId: $finding['id']);
    $this->client->addPullRequestComment('12', 'Because the loop stops one early.', parentId: $question['id']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/12/comments')
        && str_starts_with((string) $request['body'], "↪ [In reply to this comment](https://github.com/acme/shop/pull/12#discussion_r{$question['id']})\n\n"));
});

it('edits either kind of comment, and resolves a thread or folds a comment away as resolved', function () {
    $inline = $this->client->addPullRequestComment('12', 'On a line.', 'app/Export.php', 3);
    $general = $this->client->addPullRequestComment('12', 'On the pull request.');

    $this->client->updatePullRequestComment('12', $inline['id'], 'On a line, edited.');
    $this->client->updatePullRequestComment('12', $general['id'], 'On the pull request, edited.');
    $this->client->resolvePullRequestComment('12', $inline['id']);
    $this->client->resolvePullRequestComment('12', $general['id']);

    $comments = collect((new GitHubClient('acme', 'shop', 'github-token'))->getPullRequestComments('12'))->keyBy('id');

    expect($comments[$inline['id']]['content']['raw'])->toBe('On a line, edited.')
        ->and($comments[$general['id']]['content']['raw'])->toBe('On the pull request, edited.')
        ->and($comments[$inline['id']]['resolution'])->toBe(['type' => 'resolved'])
        ->and($comments[$general['id']]['resolution'])->toBe(['type' => 'resolved']);

    $this->client->resolvePullRequestComment('12', $inline['id'], false);

    expect(collect((new GitHubClient('acme', 'shop', 'github-token'))->getPullRequestComments('12'))->keyBy('id')[$inline['id']])->not->toHaveKey('resolution');
});

it('approves once, requests changes in its place, and withdraws by dismissing its own review', function () {
    expect($this->client->approve('12', true))->toBe(200)
        // Approving again while that stands adds nothing to the pull request.
        ->and($this->client->approve('12', true))->toBe(200)
        ->and($this->github->reviews)->toHaveCount(1);

    // Nothing to withdraw is not a failure, as Bitbucket's 404 is not.
    expect($this->client->requestChanges('12', false))->toBe(404)
        ->and($this->client->requestChanges('12', true))->toBe(200)
        ->and($this->github->reviews[1])->toMatchArray(['state' => 'CHANGES_REQUESTED'])
        ->and($this->github->reviews[1]['body'])->not->toBe('')
        // The request for changes is what stands now, not the approval.
        ->and($this->client->approve('12', false))->toBe(404);

    expect($this->client->requestChanges('12', false))->toBe(200)
        ->and($this->github->reviews[1]['state'])->toBe('DISMISSED');
});

it('shows the review as a commit status, and posts nothing without a token', function () {
    $this->client->setBuildStatus($this->sha, BuildState::InProgress, 'https://review.example.com/run', 'Reviewing');
    $this->client->setBuildStatus($this->sha, BuildState::Failed, 'https://review.example.com/run', str_repeat('x', 300));

    expect($this->github->statuses)->toHaveCount(2)
        ->and($this->github->statuses[0])->toMatchArray(['context' => 'AI Review', 'state' => 'pending'])
        ->and($this->github->statuses[1]['state'])->toBe('failure')
        ->and(mb_strlen($this->github->statuses[1]['description']))->toBe(140);

    $public = new GitHubClient('acme', 'shop');
    $public->setBuildStatus($this->sha, BuildState::Successful, 'https://review.example.com/run', 'Done');

    expect($public->publishes())->toBeFalse()
        ->and($this->client->publishes())->toBeTrue()
        ->and($this->client->supportsTasks())->toBeFalse()
        ->and($this->github->statuses)->toHaveCount(2);
});

it('posts a whole review on GitHub: the description, its findings, and changes requested instead of tasks', function () {
    $this->fileReview([
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => 7, 'pull_request_id' => 12,
        'result' => 'FAILED', 'summary' => 'One finding.',
        'findings' => [[
            'id' => 'F1', 'status' => 'new', 'severity' => 'HIGH', 'category' => 'correctness', 'confidence' => 9,
            'title' => 'The export skips the last row', 'details' => 'The loop ends one early.', 'path' => 'app/Export.php', 'line' => 12,
        ]],
    ])->assertOk()
        ->assertJsonPath('publish.published', true)
        ->assertJsonPath('publish.tasks.skipped', 'the host has no tasks; the request for changes holds the merge');

    expect($this->github->pull['body'])->toContain('The export skips the last row')
        ->and($this->github->reviewComments)->toHaveCount(1)
        ->and($this->github->reviewComments[0])->toMatchArray(['path' => 'app/Export.php', 'line' => 12])
        ->and(collect($this->github->reviews)->pluck('state')->all())->toBe(['CHANGES_REQUESTED']);

    // The next run finds it fixed: the thread is resolved, and the pull request approved.
    $this->fileReview([
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => 8, 'pull_request_id' => 12,
        'result' => 'PASSED', 'summary' => 'Fixed.',
        'findings' => [[
            'id' => 'F1', 'status' => 'fixed', 'severity' => 'HIGH', 'category' => 'correctness', 'confidence' => 9,
            'title' => 'The export skips the last row', 'details' => 'The loop ends one early.', 'path' => 'app/Export.php', 'line' => 12,
        ]],
    ])->assertOk();

    expect(collect($this->github->threads)->first()['resolved'])->toBeTrue()
        ->and(collect($this->github->reviewComments)->last()['body'])->toStartWith('Fixed in')
        ->and(collect($this->github->reviews)->pluck('state')->all())->toBe(['DISMISSED', 'APPROVED']);
});

it('still says a finding was fixed when GitHub will not resolve its thread', function () {
    $report = fn (int $build, string $status, string $result): array => [
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => $build, 'pull_request_id' => 12, 'result' => $result, 'summary' => 'Reviewed.',
        'findings' => [[
            'id' => 'F1', 'status' => $status, 'severity' => 'HIGH', 'category' => 'correctness', 'confidence' => 9,
            'title' => 'The export skips the last row', 'details' => 'The loop ends one early.', 'path' => 'app/Export.php', 'line' => 12,
        ]],
    ];

    $this->fileReview($report(7, 'new', 'FAILED'))->assertOk();

    $this->github->graphqlFails = true;
    // Counted, with GitHub's reason, for the run's log to say.
    $this->fileReview($report(8, 'fixed', 'PASSED'))->assertOk()
        ->assertJsonPath('publish.comments.not_resolved', 1)
        ->assertJsonPath('publish.comments.resolve_error', fn (string $error): bool => $error !== '');

    expect(collect($this->github->threads)->first()['resolved'])->toBeFalse()
        ->and(collect($this->github->reviewComments)->last()['body'])->toStartWith('Fixed in');

    // Once GitHub answers again, the next run resolves the thread it could not,
    // though it no longer reports the finding, which is settled.
    $this->github->graphqlFails = false;
    $this->fileReview(['findings' => []] + $report(9, 'fixed', 'PASSED'))->assertOk();

    expect(collect($this->github->threads)->first()['resolved'])->toBeTrue();
});

it('says a finding is fixed on its first line, and leaves its thread open without a word where GitHub refuses to resolve it', function () {
    Exceptions::fake();
    $report = fn (int $build, string $status, string $result): array => [
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => $build, 'pull_request_id' => 12, 'result' => $result, 'summary' => 'Reviewed.',
        'findings' => [[
            'id' => 'F1', 'status' => $status, 'severity' => 'HIGH', 'category' => 'correctness', 'confidence' => 9,
            'title' => 'The export skips the last row', 'details' => 'The loop ends one early.', 'path' => 'app/Export.php', 'line' => 12,
        ]],
    ];

    $this->fileReview($report(7, 'new', 'FAILED'))->assertOk();
    $finding = $this->github->reviewComments[0]['body'];

    $this->github->mutationsRefused = true;
    $this->fileReview($report(8, 'fixed', 'PASSED'))->assertOk()->assertJsonPath('publish.comments.not_resolved', 1);

    $fixed = $this->github->reviewComments[0]['body'];

    // The status on its first line, as the description lists it; the rest as it was.
    expect(strtok($fixed, "\n"))->toBe(strtok($finding, "\n").' · ✅ Fixed')
        ->and(substr($fixed, strpos($fixed, "\n")))->toContain('The export skips the last row')
        ->and(collect($this->github->threads)->first()['resolved'])->toBeFalse()
        ->and(collect($this->github->reviewComments)->last()['body'])->toStartWith('Fixed in');

    // A permission left out on purpose: nothing for the application's log.
    Exceptions::assertNothingReported();
});

it('resolves a settled finding\'s open thread only where nobody but the review wrote under it', function () {
    $finding = ['id' => 'F1', 'status' => 'new', 'severity' => 'HIGH', 'category' => 'correctness', 'confidence' => 9,
        'title' => 'The export skips the last row', 'details' => 'The loop ends one early.', 'path' => 'app/Export.php', 'line' => 12];
    $report = fn (int $build, array $findings, string $result): array => [
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => $build, 'pull_request_id' => 12, 'result' => $result, 'summary' => 'Reviewed.', 'findings' => $findings,
    ];

    $this->fileReview($report(7, [$finding], 'FAILED'))->assertOk();
    $this->github->graphqlFails = true;
    $this->fileReview($report(8, [['status' => 'fixed'] + $finding], 'PASSED'))->assertOk();
    $this->github->graphqlFails = false;

    // Someone wrote under it after the review's reply: theirs to close.
    $thread = array_key_first($this->github->threads);
    $this->github->reviewComments[] = ['id' => $this->github->next++, 'body' => 'Not quite, see the import.', 'path' => 'app/Export.php', 'line' => 12,
        'in_reply_to_id' => $this->github->reviewComments[0]['id'], 'user' => ['login' => 'nora', 'id' => 5], 'created_at' => now()->addMinute()->toIso8601String()];
    $this->github->threads[$thread]['comments'][] = $this->github->next - 1;

    $this->fileReview($report(9, [], 'PASSED'))->assertOk();

    expect($this->github->threads[$thread]['resolved'])->toBeFalse();
});

it('takes only a delivery signed with the connection\'s secret, and starts a review for a new commit', function () {
    Queue::fake();

    $this->call('POST', '/api/webhooks/hook-gh', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'pull_request', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong',
    ], '{}')->assertForbidden();

    ($this->deliver)('ping', ['zen' => 'Keep it logically awesome.', 'hook_id' => 1])->assertOk();
    Queue::assertNothingPushed();

    ($this->deliver)('pull_request', ['action' => 'synchronize', 'pull_request' => ($this->pull)(), 'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']]])->assertOk();

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->repositoryId === $this->repository->id && $job->branch === 'feature/export');
    expect($this->repository->refresh()->webhook_received_at)->not->toBeNull();
});

it('starts a review on a /review comment on the pull request, reading its branch from GitHub', function () {
    Queue::fake();

    ($this->deliver)('issue_comment', [
        'action' => 'created',
        'issue' => ['number' => 12, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/shop/pulls/12']],
        'comment' => ['id' => 501, 'body' => '/review please', 'user' => ['login' => 'nora', 'id' => 5]],
        'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']],
    ])->assertOk();

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/export');

    // A comment on an issue that is not a pull request starts nothing.
    Queue::fake();
    ($this->deliver)('issue_comment', [
        'action' => 'created', 'issue' => ['number' => 3],
        'comment' => ['id' => 502, 'body' => '/review', 'user' => ['login' => 'nora', 'id' => 5]],
        'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']],
    ])->assertOk();

    Queue::assertNothingPushed();
});

it('adds the webhook to the organisation, or to each repository when it may not', function () {
    $this->github->orgHooks = [];
    app(Connections::class)->installWebhook($this->connection);

    expect($this->github->orgHooks)->toHaveCount(1)
        ->and($this->github->orgHooks[0])->toMatchArray(['name' => 'web', 'events' => ['pull_request', 'issue_comment', 'pull_request_review_comment', 'pull_request_review']])
        ->and($this->github->orgHooks[0]['config'])->toMatchArray(['url' => route('api.webhooks', ['key' => 'hook-gh']), 'content_type' => 'json', 'secret' => 'secret'])
        ->and($this->github->hooks)->toBe([]);

    // Asked again, the hook that is there is updated rather than a second added.
    $this->github->orgHooks[0]['id'] = 1;
    app(Connections::class)->installWebhook($this->connection);

    expect($this->github->orgHooks)->toHaveCount(1);

    // An organisation whose hooks the token may not manage: each repository instead.
    $this->github->orgHooks = false;
    app(Connections::class)->installWebhook($this->connection);

    expect($this->github->hooks['shop'])->toHaveCount(1);
});

it('says what is missing when there is nowhere to add the webhook', function () {
    Repository::query()->delete();

    expect(fn () => app(Connections::class)->installWebhook($this->connection))
        ->toThrow(ConnectionFailed::class, 'Add a repository first');
});

describe('a pull request from a fork', function () {
    beforeEach(function () {
        // Someone's fork, whose branch is called what this repository's main is.
        $this->github->pull = ['head' => ['ref' => 'main', 'label' => 'someone:main', 'repo' => ['full_name' => 'someone/shop']]];
        $this->event = fn (string $action = 'synchronize'): array => ['action' => $action, 'pull_request' => ($this->pull)($this->github->pull), 'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']]];
    });

    it('goes by its pull ref, not by what the fork calls its branch', function () {
        $pullRequest = $this->client->getPullRequest('12');

        expect($pullRequest['source']['branch']['name'])->toBe('pull/12/head')
            ->and($pullRequest)->toMatchArray(['from_fork' => true, 'fork_branch' => 'someone:main'])
            ->and($this->client->findOpenPullRequestByBranch('pull/12/head')['id'])->toBe(12);
    });

    it('is not reviewed by itself until the tenant turns that on, and then as itself', function () {
        Queue::fake();

        ($this->deliver)('pull_request', ($this->event)())->assertOk();

        Queue::assertNothingPushed();
        // Nobody asked, so nothing is said on it.
        expect($this->github->issueComments)->toBe([]);

        $this->repository->tenant->update(['review_forks' => true]);
        ($this->deliver)('pull_request', ($this->event)())->assertOk();

        // Never this repository's main, which the fork's branch is also called.
        Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'pull/12/head');
    });

    it('says once why a /review comment or a question on it starts nothing', function () {
        Queue::fake();
        $comment = fn (int $id, string $body): array => [
            'action' => 'created',
            'issue' => ['number' => 12, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/shop/pulls/12']],
            'comment' => ['id' => $id, 'body' => $body, 'user' => ['login' => 'someone', 'id' => 9]],
            'repository' => ['name' => 'shop', 'owner' => ['login' => 'acme']],
        ];

        ($this->deliver)('issue_comment', $comment(601, '/review'))->assertOk();
        ($this->deliver)('issue_comment', $comment(601, '/review'))->assertOk();
        ($this->deliver)('issue_comment', $comment(602, '@review why is this slow?'))->assertOk();

        Queue::assertNothingPushed();
        expect($this->github->issueComments)->toHaveCount(2)
            ->and($this->github->issueComments[0]['body'])->toContain('Pull requests from forks are not reviewed here by themselves')
            ->and($this->github->issueComments[1]['body'])->toContain('Pull requests from forks are not reviewed here by themselves');
    });

    it('is cloned and brought up to date through its pull ref', function () {
        Process::fake(function ($process) {
            $command = (array) $process->command;

            if ($command[1] === 'clone') {
                File::ensureDirectoryExists(end($command));
            }

            return Process::result(in_array('rev-parse', $command, true) ? str_repeat('f', 40)."\n" : '');
        });
        $directory = storage_path('framework/testing/review-work/fork');

        expect(app(Checkout::class)->clone($this->repository, 'pull/12/head', null, $directory))->toBe(str_repeat('f', 40));
        app(Checkout::class)->update($this->repository, $directory, 'pull/12/head', null);

        // The repository's main branch, not one called after the fork's branch.
        Process::assertRan(fn ($process): bool => ((array) $process->command)[1] === 'clone' && ! in_array('--branch', (array) $process->command, true));
        Process::assertRan(fn ($process): bool => in_array('+refs/pull/12/head:refs/remotes/origin/pull/12/head', (array) $process->command, true));
        Process::assertRan(fn ($process): bool => in_array('origin/pull/12/head', (array) $process->command, true));

        File::deleteDirectory(storage_path('framework/testing/review-work'));
    });
});

it('says whether a pull request merges, once GitHub has worked it out', function () {
    $answers = [null, false, true];

    // In place of the fake every test here starts with.
    Http::swap(new HttpFactory);
    Http::fake(['api.github.com/repos/acme/shop/pulls/12' => function () use (&$answers) {
        return Http::response(['number' => 12, 'mergeable' => array_shift($answers)]);
    }]);

    expect($this->client->mergeConflicts('12'))->toBeNull()
        ->and($this->client->mergeConflicts('12'))->toBe(['conflicts' => true, 'files' => []])
        ->and($this->client->mergeConflicts('12'))->toBe(['conflicts' => false, 'files' => []]);
});

it('reads a Bitbucket conflict on any file, also one named 0', function () {
    Http::swap(new HttpFactory);
    Http::fake(['api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/5/conflicts' => Http::response(['values' => [['path' => '0', 'scenario' => 'content']]])]);

    $client = Repository::factory()->create(['workspace' => 'acme', 'slug' => 'shop'])->sourceControl();

    expect($client->mergeConflicts('5'))->toBe(['conflicts' => true, 'files' => ['0']]);
});
