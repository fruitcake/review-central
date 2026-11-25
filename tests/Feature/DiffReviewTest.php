<?php

use App\Jobs\RunContainerReview;
use App\Jobs\RunDiffReview;
use App\Llm\Agents\Classifier;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Runners\Container\RunLog;
use App\Runners\Container\Trial;
use App\Runners\Diff\Diff;
use App\Runners\Diff\DiffReview;
use App\Runners\Diff\ReportSchema;
use App\Runners\DiffRunner;
use App\Services\ReviewLock;
use App\Services\ReviewRequest;
use App\Services\ReviewSettings;
use App\Services\ReviewTrial;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->sha = str_repeat('a', 40);
    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_runner' => 'diff']);
    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);

    ReviewInstruction::query()->create(['key' => 'method', 'title' => 'Pull request review', 'position' => 10, 'body' => "Read every caller in the repository.\n\nLook for a missing tenant scope."]);

    $this->diff = "diff --git a/app/Export.php b/app/Export.php\n--- a/app/Export.php\n+++ b/app/Export.php\n@@ -10,3 +10,4 @@\n     public function rows()\n     {\n+        return Invoice::all();\n     }\n"
        ."diff --git a/composer.lock b/composer.lock\n--- a/composer.lock\n+++ b/composer.lock\n@@ -1 +1 @@\n-old\n+new\n";

    $this->report = [
        'result' => 'FAILED',
        'summary' => "1 medium.\n\nAdds the export.",
        'changes' => ['Adds an export of invoices'],
        'before_release' => [],
        'manual_review' => [],
        'findings' => [[
            'id' => 'F1', 'severity' => 'MEDIUM', 'status' => 'new', 'confidence' => 9, 'path' => 'app/Export.php', 'line' => 12,
            'title' => 'Export shows other companies\' invoices', 'anchor' => 'return Invoice::all();', 'details' => 'Every invoice is exported.',
        ]],
    ];
});

/**
 * The host of the repository, with one open pull request from feature/export.
 *
 * @param  array<string, mixed>  $pullRequest
 */
function fakeHost(string $diff, array $pullRequest = []): void
{
    $pullRequest += [
        'id' => 570, 'title' => 'Add the export', 'description' => 'Adds the export.', 'state' => 'OPEN', 'draft' => false,
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
        'destination' => ['branch' => ['name' => 'main']],
    ];

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests?*' => Http::response(['values' => [$pullRequest]]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/diff' => Http::response($diff),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [], 'id' => 900]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/tasks*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/*' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response($pullRequest),
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*/statuses/build' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*' => Http::response(['hash' => str_repeat('a', 40), 'message' => 'Add the export']),
    ]);
}

/**
 * Anthropic's answer as it streams it.
 *
 * @param  array<string, mixed>  $report
 */
function streamed(array $report, string $stop = 'end_turn', string $model = 'claude-opus-5-5'): string
{
    $text = json_encode($report);
    $events = [
        ['type' => 'message_start', 'message' => ['model' => $model, 'usage' => ['input_tokens' => 1200, 'cache_creation_input_tokens' => 800, 'cache_read_input_tokens' => 0, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 0, 40)]],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 40)]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => 350]],
        ['type' => 'message_stop'],
    ];

    return implode('', array_map(fn (array $event): string => "event: {$event['type']}\ndata: ".json_encode($event)."\n\n", $events));
}

it('reviews a pull request from its diff in one request, and posts what comes back', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream'])]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41, waited: 4);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
            return false;
        }

        $system = $request['system'][0]['text'];
        $material = (string) json_encode($request['messages'][0]['content'], JSON_UNESCAPED_SLASHES);

        return $request->hasHeader('x-api-key', 'sk-ant-one')
            && $request['model'] === 'claude-opus-5-5'
            && $request['stream'] === true
            // A decline by a safety classifier is run again on another model.
            && $request['fallbacks'] === 'default' && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $request['output_config']['format']['type'] === 'json_schema'
            && $request['system'][0]['cache_control'] === ['type' => 'ephemeral']
            && str_contains($system, 'Reviewing a pull request from its diff')
            && str_contains($system, 'Look for a missing tenant scope.')
            // The one file of guidance goes whole, under this runner's own word on
            // which of its steps a reviewer without the repository leaves out.
            && str_contains($system, 'Ignore any step in them that asks you to read or search the repository')
            && str_contains($material, 'Add the export')
            && str_contains($material, '+        return Invoice::all();')
            // A lock file is named and not sent.
            && str_contains($material, 'composer.lock (generated)')
            && ! str_contains($material, '+new');
    });

    $run = ReviewRun::query()->sole();

    expect($run->only(['status', 'result', 'runner', 'agent', 'provider', 'model', 'commit', 'build_number', 'pull_request_id', 'queue_seconds']))->toBe([
        'status' => ReviewRun::STATUS_FINISHED, 'result' => 'FAILED', 'runner' => 'diff', 'agent' => 'diff', 'provider' => 'anthropic',
        'model' => 'claude-opus-5-5', 'commit' => $this->sha, 'build_number' => 41, 'pull_request_id' => 570, 'queue_seconds' => 4,
    ])
        ->and($run->usage)->toBe(['claude-opus-5-5' => ['input' => 1200, 'output' => 350, 'cache_read' => 0, 'cache_creation' => 800]])
        ->and($run->findings[0]['title'])->toBe('Export shows other companies\' invoices');

    // On the pull request: the finding as a comment, a request for changes, and the
    // review among its builds, first as running and then as how it ended.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/comments')
        && str_contains($request['content']['raw'], 'Export shows other companies'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/request-changes'));

    $states = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), "/commit/{$this->sha}/statuses/build"))
        ->map(fn (array $pair): string => $pair[0]['state'])->values()->all();

    expect($states)->toBe(['INPROGRESS', 'FAILED'])
        // Nothing is held afterwards, and nothing was cloned or run.
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(app(DiffRunner::class)->isRunning($this->repository, '41'))->toBeFalse()
        ->and(app(RunLog::class)->tail($run)['text'])->toContain('Left out: composer.lock (generated)', 'claude-opus-5-5 answered: 1 finding(s)', 'the review is on the pull request');

    Process::assertNothingRan();
});

it('asks nobody when there is nothing to review', function () {
    fakeHost($this->diff, ['draft' => true]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(ReviewRun::query()->count())->toBe(0)
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic.com') || str_contains($request->url(), '/diff') || str_contains($request->url(), '/statuses/build'));
});

it('closes the run as failed when the provider gives no report, and says why in the log', function (string $stop, string $reason) {
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report, $stop))]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    $run = ReviewRun::query()->sole();

    expect($run->status)->toBe(ReviewRun::STATUS_FAILED)
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(app(RunLog::class)->tail($run)['text'])->toContain("The review did not finish: claude-opus-5-5 {$reason}");

    // Nothing of a report that was cut off or refused reaches the pull request.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/pullrequests/570/'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/statuses/build') && $request['state'] === 'STOPPED');
})->with([
    'a refusal' => ['refusal', 'declined to review this pull request.'],
    'an answer that ran out of room' => ['max_tokens', 'ran out of room before the report was complete.'],
]);

it('says what Anthropic answered when it refuses the request', function () {
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'model: claude-nine is not a model']], 400)]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(app(RunLog::class)->tail(ReviewRun::query()->sole())['text'])->toContain('Anthropic answered 400: model: claude-nine is not a model');
});

it('asks OpenAI where the settings pick Codex, with the model the repository set', function () {
    fakeHost($this->diff);
    Http::fake(['api.openai.com/v1/responses' => Http::response([
        'id' => 'resp_1', 'model' => 'gpt-test', 'status' => 'completed',
        // Strict output answers null where the report leaves a field out.
        'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode(['reviewed' => null, 'skipped' => null] + $this->report + ['findings' => [$this->report['findings'][0] + ['suggestion' => null]]])]]]],
        'usage' => ['input_tokens' => 2000, 'output_tokens' => 300, 'input_tokens_details' => ['cached_tokens' => 500]],
    ])]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Connection::factory()->openai('sk-openai-one')->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewSettings::saveFor($this->repository, ['providers' => [['provider' => 'codex-cli', 'share' => 100]], 'codex_model' => 'gpt-test', 'codex_effort' => 'xhigh']);

    app(DiffReview::class)->run($this->repository->refresh(), 'feature/export', null, 41);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer sk-openai-one')
        && $request['model'] === 'gpt-test'
        && $request['reasoning']['effort'] === 'high'
        && $request['text']['format']['strict'] === true
        && $request['text']['format']['schema']['required'] === array_keys($request['text']['format']['schema']['properties'])
        && str_contains((string) json_encode($request['input'], JSON_UNESCAPED_SLASHES), 'return Invoice::all();'));

    $run = ReviewRun::query()->sole();

    expect($run->only(['status', 'provider', 'model']))->toBe(['status' => ReviewRun::STATUS_FINISHED, 'provider' => 'openai', 'model' => 'gpt-test'])
        ->and($run->usage)->toBe(['gpt-test' => ['input' => 1500, 'output' => 300, 'cache_read' => 500, 'cache_creation' => 0]])
        ->and($run->findings[0])->not->toHaveKey('suggestion');
});

it('starts a review of the diff as a job of its own, from the webhook too', function () {
    Queue::fake();

    $build = app(DiffRunner::class)->start($this->repository, 'feature/export');

    Queue::assertPushedOn('reviews', RunDiffReview::class);
    Queue::assertPushed(fn (RunDiffReview $job): bool => $job->repositoryId === $this->repository->id && $job->branch === 'feature/export' && $job->build === $build);

    expect(app(DiffRunner::class)->isRunning($this->repository, (string) $build))->toBeTrue();

    $body = json_encode(['repository' => ['full_name' => 'acme/shop'], 'pullrequest' => [
        'id' => 571, 'state' => 'OPEN', 'draft' => false, 'updated_on' => '2026-11-18T10:00:00+00:00',
        'source' => ['branch' => ['name' => 'feature/other'], 'commit' => ['hash' => str_repeat('b', 12)]],
    ]]);

    $this->call('POST', '/api/webhooks/hook-one', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'pullrequest:updated', 'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
    ], $body)->assertOk();

    Queue::assertPushed(fn (RunDiffReview $job): bool => $job->branch === 'feature/other');
    Queue::assertNotPushed(RunContainerReview::class);
});

it('lets go of what a job that died held', function () {
    fakeHost($this->diff);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => $this->sha, 'build_number' => 41, 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);
    ReviewLock::acquire($this->repository, 'feature/export', '41');

    (new RunDiffReview($this->repository->id, 'feature/export', null, 41))->failed(new RuntimeException('Killed'));

    expect($run->refresh()->status)->toBe(ReviewRun::STATUS_FAILED)
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/statuses/build') && $request['state'] === 'STOPPED');
});

it('leaves generated and binary files out of the diff, and whole files once it is too large', function () {
    $file = fn (string $path, string $body): string => "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n{$body}";
    $diff = Diff::from(
        $file('app/A.php', "@@ -1 +1 @@\n+a\n")
        .$file('package-lock.json', "@@ -1 +1 @@\n+lock\n")
        .$file('public/build/app.js', "@@ -1 +1 @@\n+built\n")
        ."diff --git a/logo.png b/logo.png\nBinary files a/logo.png and b/logo.png differ\n"
        .$file('app/Large.php', "@@ -1 +1 @@\n+".str_repeat('x', 400)."\n")
        .$file('app/B.php', "@@ -1 +1 @@\n+b\n"),
        300,
    );

    expect($diff->text)->toContain('app/A.php', 'app/B.php')->not->toContain('lock', 'built', 'Large.php')
        ->and($diff->leftOut)->toBe(['package-lock.json (generated)', 'public/build/app.js (generated)', 'logo.png (binary)', 'app/Large.php (the diff is too large to send whole)'])
        ->and($diff->cutShort())->toBeTrue()
        ->and(Diff::from($file('yarn.lock', "+x\n"), 300)->cutShort())->toBeFalse();
});

it('gives each provider the report\'s schema in the form its API takes', function () {
    $closed = ReportSchema::closed();
    $strict = ReportSchema::strict();
    $finding = fn (array $schema): array => $schema['properties']['findings']['items'];

    expect($closed['additionalProperties'])->toBeFalse()
        ->and($finding($closed)['additionalProperties'])->toBeFalse()
        ->and($closed['required'])->toBe(['result', 'summary', 'findings'])
        ->and($finding($closed)['properties']['id'])->toBe(['type' => 'string'])
        ->and($finding($closed)['properties']['line'])->toBe(['type' => 'integer'])
        // Strict: everything is required, and what was optional may be null.
        ->and($strict['required'])->toBe(array_keys($strict['properties']))
        ->and($finding($strict)['required'])->toBe(array_keys($finding($strict)['properties']))
        ->and($finding($strict)['properties']['severity'])->toBe(['type' => 'string', 'enum' => ['HIGH', 'MEDIUM', 'LOW']])
        ->and($finding($strict)['properties']['category']['enum'])->toContain(null)
        ->and($finding($strict)['properties']['line']['type'])->toBe(['integer', 'null'])
        ->and($finding($strict)['properties']['introduced'])->toBe(['type' => ['boolean', 'null'], 'enum' => [false, null]])
        ->and(ReportSchema::withoutNulls(['a' => null, 'b' => [['c' => null, 'd' => 1], ['e' => 2]]]))->toBe(['b' => [['d' => 1], ['e' => 2]]]);
});

it('hands back the review it queued when someone starts one, for a link to it', function () {
    Queue::fake();
    fakeHost($this->diff);
    config(['review.diff.enabled' => true]);

    $run = app(ReviewRequest::class)->start($this->repository, 'feature/export', strict: true);

    expect($run?->is(ReviewRun::query()->sole()))->toBeTrue()
        ->and($run->status)->toBe(ReviewRun::STATUS_QUEUED);
});

it('closes a queued review as skipped when its pull request was closed before a worker got to it', function () {
    Queue::fake();
    fakeHost($this->diff);
    $build = app(DiffRunner::class)->start($this->repository, 'feature/export');

    expect(ReviewRun::query()->sole()->status)->toBe(ReviewRun::STATUS_QUEUED);

    $run = ReviewRun::query()->sole();

    // Merged meanwhile: the branch has no open pull request any more. A fresh
    // fake, because a stub that was set earlier keeps answering.
    Http::swap(new Factory);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests?*' => Http::response(['values' => []]),
        'api.bitbucket.org/*' => Http::response([]),
    ]);

    app(DiffReview::class)->run($this->repository, 'feature/export', null, $build);

    expect($run->refresh()->status)->toBe(ReviewRun::STATUS_SKIPPED);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/statuses/build') && $request['state'] === 'STOPPED' && $request['description'] === 'Nothing to review');
});

it('tries a pull request from its diff without posting anything, also one that was merged', function () {
    Queue::fake();
    fakeHost($this->diff, ['state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('a', 12)]]);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report))]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewLock::acquire($this->repository, 'feature/export', '7');

    // The repository's own reviews are in a container: this run is not one of them.
    $this->repository->update(['review_runner' => 'docker']);

    app(ReviewTrial::class)->start($this->repository->tenant, 'https://bitbucket.org/acme/shop/pull-requests/570', ['runner' => 'diff', 'model' => 'claude-sonnet-5-5']);

    // The other runner's job, and nothing started in a container.
    Queue::assertNotPushed(RunContainerReview::class);
    Queue::assertPushed(function (RunDiffReview $job): bool {
        app(DiffReview::class)->run($this->repository, $job->branch, $job->commit, $job->build, null, $job->trial);

        return $job->trial->pullRequestId === 570 && $job->trial->provider === null;
    });

    $run = ReviewRun::query()->sole();

    expect($run->only(['status', 'custom', 'runner', 'agent', 'model', 'pull_request_id']))->toBe([
        'status' => ReviewRun::STATUS_UNPUBLISHED, 'custom' => true, 'runner' => 'diff', 'agent' => 'diff', 'model' => 'claude-opus-5-5', 'pull_request_id' => 570,
    ])
        ->and($run->findings)->toHaveCount(1)
        // The lock of the pull request's real review is still that review's.
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBe('7');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages' && $request['model'] === 'claude-sonnet-5-5');
    // Nothing on the pull request: no comment, no verdict, no build status.
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), 'bitbucket.org'));
});

it('skips a trial of a commit the pull request has moved on from, whose diff it no longer has', function () {
    Queue::fake();
    fakeHost($this->diff);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    $build = app(DiffRunner::class)->startTrial($this->repository, 'feature/export', new Trial(pullRequestId: 570, destination: 'main', commit: str_repeat('b', 40)));
    app(DiffReview::class)->run($this->repository, 'feature/export', null, $build, null, new Trial(pullRequestId: 570, destination: 'main', commit: str_repeat('b', 40)));

    expect(ReviewRun::query()->sole()->only(['status', 'summary']))->toMatchArray(['status' => ReviewRun::STATUS_SKIPPED])
        ->and(ReviewRun::query()->sole()->summary)->toContain('moved on from bbbbbbbbbbbb');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com'));
});

it('closes a trial whose job died without telling the pull request or touching its lock', function () {
    fakeHost($this->diff);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => $this->sha, 'build_number' => 41, 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_RUNNING, 'custom' => true, 'started_at' => now(),
    ]);
    // The pull request's own review is running beside it.
    ReviewLock::acquire($this->repository, 'feature/export', '7');

    (new RunDiffReview($this->repository->id, 'feature/export', null, 41, null, new Trial(pullRequestId: 570, destination: 'main')))->failed(new RuntimeException('Killed'));

    expect($run->refresh()->status)->toBe(ReviewRun::STATUS_FAILED)
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBe('7');

    Http::assertNothingSent();
});

it('waits through the silence of a model that thinks before it writes', function () {
    Process::fake();
    fakeHost($this->diff);
    config(['review.diff.timeout' => 900]);
    $waits = null;
    Http::fake(['api.anthropic.com/v1/messages' => function (Request $request, array $options) use (&$waits) {
        $waits = $options['read_timeout'] ?? null;

        return Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream']);
    }]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41, waited: 4);

    // A streamed answer is otherwise dropped after a minute without a byte.
    expect($waits)->toBe(900);
});

it('says in the log that the provider did not answer, and of nobody else', function () {
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => fn () => throw new ConnectionException('Idle timeout reached')]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    rescue(fn () => app(DiffReview::class)->run($this->repository, 'feature/export', null, 41), report: false);

    expect(app(RunLog::class)->tail(ReviewRun::query()->sole())['text'])
        ->toContain('The connection to Anthropic was lost, or the answer took longer than the 900 seconds a review may take.')
        ->toContain('The review did not finish: ConnectionException.');
});

it('does not follow a gateway that redirects, with the tenant\'s key in hand', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake([
        'gateway.example.com/*' => Http::response('', 307, ['Location' => 'https://internal.example.com/v1/messages']),
        'internal.example.com/*' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream']),
    ]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id, 'settings' => ['base_url' => 'https://gateway.example.com']]);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41, waited: 4);

    // The address a tenant names was checked to be public; where it sends us was not.
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://gateway.example.com/'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.example.com'));

    expect(ReviewRun::query()->sole()->status)->toBe(ReviewRun::STATUS_FAILED);
});

it('asks Anthropic for a repository on Claude, also when another provider was connected first', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream'])]);
    // Connected first, and not the provider this repository reviews with.
    Connection::query()->create(['tenant_id' => $this->repository->tenant_id, 'kind' => 'llm', 'driver' => 'gemini', 'name' => 'Gemini', 'credentials' => ['api_key' => 'key-gemini'], 'settings' => ['model' => 'gemini-test']]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(ReviewRun::query()->sole()->only(['status', 'provider']))->toBe(['status' => ReviewRun::STATUS_FINISHED, 'provider' => 'anthropic']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
});

it('writes in the team\'s language and leaves the ticket ungraded when the team says so', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream'])]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewSettings::saveDefaults($this->repository->tenant, ['language' => 'de', 'ticket_grading' => 'false']);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && str_contains($request['system'][0]['text'], 'in German')
        && str_contains($request['system'][0]['text'], 'Do not grade the change against the ticket'));
});

it('judges the answer by the repository\'s threshold, not the platform\'s', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream'])]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    ReviewSettings::saveFor($this->repository, ['fail_on' => ['default' => 'high']]);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(ReviewRun::query()->sole()->only(['result', 'fail_on']))->toBe(['result' => 'PASSED', 'fail_on' => 'high,security:any,maintainability:never']);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/request-changes'));
});

it('runs a pull request at the effort and model its classification picks, from the diff too', function () {
    Process::fake();
    fakeHost($this->diff);
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(streamed($this->report), 200, ['Content-Type' => 'text/event-stream'])]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Classifier::fake([['complexity' => 'complex', 'kind' => 'feature', 'database' => false, 'breaking' => false, 'security' => true, 'irreversible' => false, 'reason' => 'It exports every invoice.']]);
    ReviewSettings::saveDefaults($this->repository->tenant, ['strategy' => 'apply', 'claude_effort' => 'low']);

    app(DiffRunner::class)->markRunning($this->repository, 41);
    app(DiffReview::class)->run($this->repository, 'feature/export', null, 41);

    $run = ReviewRun::query()->sole();

    expect($run->effort)->toBe('xhigh')
        ->and($run->model)->toBe(ReviewSettings::tierModels('anthropic')['smartest'])
        ->and(app(RunLog::class)->tail($run)['text'])->toContain('Classified as Complex · feature · security: It exports every invoice. Runs at effort xhigh and the smartest model.');
});
