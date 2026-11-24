<?php

use App\Enums\ReviewCommandAccess;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Models\TicketSource;
use App\Services\ReviewContext;
use App\Services\ReviewInstructions;
use App\Services\ReviewStack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->sha = str_repeat('a', 40);
    $this->repository = $this->reviewing(Repository::factory()->create(['slug' => 'shop']));

    ReviewInstruction::query()->create(['key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => 'Review carefully.']);
});

/**
 * @param  array<string, mixed>  $pullRequest
 */
function fakeBitbucket(array $pullRequest = []): void
{
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [], 'id' => 900]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/tasks*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/*' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response(array_merge([
            'id' => 570,
            'title' => 'SHOP-12 Add the export',
            'description' => 'Adds the export.',
            'state' => 'OPEN',
            'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
            'destination' => ['branch' => ['name' => 'main']],
        ], $pullRequest)),
        'acme.atlassian.net/rest/api/2/issue/SHOP-12/comment*' => Http::response(['comments' => [], 'total' => 0]),
        'acme.atlassian.net/rest/api/2/issue/SHOP-12*' => Http::response(['key' => 'SHOP-12', 'fields' => ['summary' => 'Export orders']]),
    ]);
}

function startReview(array $input = []): TestResponse
{
    return test()->openReview(array_merge([
        'pull_request_id' => 570,
        'branch' => 'feature/export',
        'commit' => test()->sha,
        'lock' => '77',
        'instructions' => '1',
    ], $input));
}

it('hands the reviewer the pull request, the ticket and the instructions, and opens the run for the tenant', function () {
    fakeBitbucket();

    TicketSource::factory()->create([
        'connection_id' => Connection::factory()->jira()->create(['tenant_id' => $this->repository->tenant_id]),
        'prefixes' => ['SHOP'],
    ]);

    $response = startReview()->assertOk();

    $response->assertJsonPath('skip', false);
    $response->assertJsonPath('ticket.reference', 'SHOP-12');
    $response->assertJsonPath('ticket.url', 'https://acme.atlassian.net/browse/SHOP-12');
    $response->assertJsonPath('instructions.files.0.body', 'Review carefully.');
    expect($response->json('ticket.markdown'))->toContain('# SHOP-12 Export orders');

    $run = ReviewRun::query()->sole();

    expect($run->tenant_id)->toBe($this->repository->tenant_id)
        ->and($run->status)->toBe(ReviewRun::STATUS_RUNNING)
        ->and($run->build_number)->toBe(77);

    // The repository's own connection is the one Bitbucket was read with.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/pullrequests/570')
        && $request->hasHeader('Authorization', 'Bearer bitbucket-token'));
});

it('reads a tenant\'s own instruction over the default of the same key', function () {
    fakeBitbucket();

    ReviewInstruction::query()->create(['tenant_id' => $this->repository->tenant_id, 'key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => 'Our own method.']);

    startReview()->assertJsonPath('instructions.files.0.body', 'Our own method.')->assertJsonCount(1, 'instructions.files');
});

it('publishes the report on the pull request and records what the run cost', function () {
    fakeBitbucket();
    startReview()->assertOk();

    ModelPrice::query()->create(['model' => 'claude-opus-5-5', 'input' => '0.000005', 'output' => '0.000025', 'cache_read' => '0', 'cache_creation' => '0', 'fetched_at' => now()]);

    $this->fileReview([
        'branch' => 'feature/export',
        'commit' => $this->sha,
        'build' => 77,
        'pull_request_id' => 570,
        'result' => 'FAILED',
        'summary' => 'One finding.',
        'findings' => [[
            'id' => 'F1', 'status' => 'new', 'severity' => 'high', 'category' => 'correctness',
            'title' => 'The export skips the last row', 'details' => 'The loop ends one early.',
            'path' => 'app/Export.php', 'line' => 12,
        ]],
        'usage' => ['claude-opus-5-5' => ['input' => 1000, 'output' => 200]],
    ])->assertOk()->assertJsonPath('publish.published', true);

    $run = ReviewRun::query()->sole();

    expect($run->status)->toBe(ReviewRun::STATUS_FINISHED)
        ->and($run->result)->toBe('FAILED')
        ->and((float) $run->cost)->toBe(0.01)
        ->and($run->cost_estimated)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/pullrequests/570/comments')
        && str_contains($request['content']['raw'], 'The export skips the last row')
        && $request['inline'] === ['path' => 'app/Export.php', 'to' => 12]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/pullrequests/570/request-changes'));
});

it('starts the review when someone comments /review, and only for a signed delivery', function () {
    Queue::fake();
    Http::fake();

    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now()->subDay(),
    ]);

    $payload = json_encode([
        'repository' => ['full_name' => 'acme/shop'],
        'pullrequest' => ['id' => 570, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export']]],
        'comment' => ['content' => ['raw' => '/review please look again']],
    ]);

    $send = fn (string $signature) => $this->call('POST', '/api/webhooks/hook-one', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'pullrequest:comment_created', 'HTTP_X_HUB_SIGNATURE' => $signature,
    ], $payload);

    $send('sha256=wrong')->assertForbidden();
    Queue::assertNothingPushed();

    $send('sha256='.hash_hmac('sha256', $payload, 'secret'))->assertOk();

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->repositoryId === $this->repository->id && $job->branch === 'feature/export');
});

it('starts a review by comment only for the accounts the tenant lets, and says so once to anyone else', function () {
    Queue::fake();
    Http::fake(['api.bitbucket.org/*' => Http::response(['id' => 901])]);

    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);
    $this->repository->tenant->update(['review_command' => ReviewCommandAccess::Listed, 'review_command_accounts' => ['dana-demo']]);

    $send = function (array $user, int $id) {
        $payload = json_encode([
            'repository' => ['full_name' => 'acme/shop'],
            'pullrequest' => ['id' => 570, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export']]],
            'comment' => ['id' => $id, 'content' => ['raw' => '/review'], 'user' => $user],
        ]);

        return $this->call('POST', '/api/webhooks/hook-one', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'pullrequest:comment_created',
            'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'secret'),
        ], $payload)->assertOk();
    };

    // Someone else, under a display name that copies a listed account's: no review, and a reply once.
    $send(['display_name' => 'dana-demo', 'nickname' => 'mallory', 'account_id' => '557058:mallory'], 81);
    $send(['display_name' => 'dana-demo', 'nickname' => 'mallory', 'account_id' => '557058:mallory'], 81);

    Queue::assertNothingPushed();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pullrequests/570/comments')
        && ($request['parent'] ?? null) === ['id' => 81]
        && str_contains($request['content']['raw'], 'Only the accounts this team has listed'));

    // The listed account, by its nickname, also where reviews do not start by themselves.
    $this->repository->update(['reviews_enabled' => false]);
    $send(['display_name' => 'Dana Demo', 'nickname' => 'Dana-Demo', 'account_id' => '557058:dana'], 82);

    Queue::assertPushed(RunContainerReview::class, 1);

    // Turned off: nobody starts one by comment.
    $this->repository->tenant->update(['review_command' => ReviewCommandAccess::Nobody]);
    $send(['display_name' => 'Dana Demo', 'nickname' => 'dana-demo', 'account_id' => '557058:dana'], 83);

    Queue::assertPushed(RunContainerReview::class, 1);
    Http::assertSent(fn (Request $request): bool => ($request['parent'] ?? null) === ['id' => 83]
        && str_contains($request['content']['raw'], 'A review is not started by comment here.'));
});

it('reviews a draft on a /review comment only from someone who may start one', function () {
    fakeBitbucket(['draft' => true]);
    $this->repository->tenant->update(['review_command' => ReviewCommandAccess::Listed, 'review_command_accounts' => ['dana-demo']]);

    $comment = fn (string $nickname): array => [
        'id' => 5, 'content' => ['raw' => '/review'], 'user' => ['display_name' => 'Someone', 'nickname' => $nickname],
        'created_on' => now()->toIso8601String(), 'updated_on' => now()->toIso8601String(),
    ];
    $pullRequest = ['id' => 570, 'draft' => true, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $this->sha]]];

    expect(ReviewContext::build($this->repository, $pullRequest, [$comment('mallory')])['reason'])->toBe('draft')
        ->and(ReviewContext::build($this->repository, $pullRequest, [$comment('dana-demo')])['skip'])->toBeFalse();
});

it('keeps one file of guidance for a pull request\'s review, and a tenant\'s own copy in its place', function () {
    // What ships: the method with the lenses in it, and a guide per stack.
    $shipped = collect(File::files(resource_path(ReviewInstructions::BUNDLED_DIRECTORY)))->map->getFilename()->sort()->values()->all();

    expect($shipped)->toBe(['method.md', 'stack-drupal.md', 'stack-hyva.md', 'stack-laravel.md', 'stack-magento.md'])
        ->and(File::get(resource_path(ReviewInstructions::BUNDLED_DIRECTORY.'/method.md')))->toContain('# Pull request review', 'DEFECT LENSES:');

    ReviewInstruction::query()->create(['key' => 'stack-laravel', 'title' => 'Laravel', 'stack' => 'laravel', 'position' => 30, 'body' => 'Mind the tenant scope.']);
    $files = fn (): array => array_column((array) ReviewInstructions::files($this->repository->tenant, ['laravel']), 'body', 'key');

    expect($files())->toBe(['method' => 'Review carefully.', 'stack-laravel' => 'Mind the tenant scope.']);

    // Saved is what the next review reads: no draft, no channel.
    ReviewInstruction::query()->create(['tenant_id' => $this->repository->tenant_id, 'key' => 'stack-laravel', 'title' => 'Laravel', 'stack' => 'laravel', 'position' => 30, 'body' => 'Ours: mind the company scope.']);

    expect($files())->toBe(['method' => 'Review carefully.', 'stack-laravel' => 'Ours: mind the company scope.']);

    // The shipped text replaces a default that differs, and never a tenant's own.
    $result = ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY), overwrite: true);

    expect($result['replaced'])->toContain('method', 'stack-laravel')
        ->and($files()['stack-laravel'])->toBe('Ours: mind the company scope.')
        ->and($files()['method'])->toContain('DEFECT LENSES:');
});

it('lets a tenant add a stack of its own, for its repositories and nobody else\'s', function () {
    ReviewInstruction::query()->create(['tenant_id' => $this->repository->tenant_id, 'key' => 'stack-symfony', 'title' => 'Symfony', 'stack' => 'symfony', 'position' => 30, 'body' => 'Mind the voters.']);
    $other = Repository::factory()->create(['review_stack' => ['symfony', 'laravel']]);

    expect(ReviewStack::known($this->repository->tenant))->toBe(['laravel', 'magento', 'hyva', 'drupal', 'symfony'])
        ->and(ReviewStack::known($other->tenant))->toBe(ReviewStack::STACKS);

    $this->repository->update(['review_stack' => ['symfony']]);

    // Chosen on the repository, its guide goes to the review beside the guidance.
    expect(ReviewStack::resolve($this->repository->refresh()))->toBe(['symfony'])
        ->and(array_column((array) ReviewInstructions::files($this->repository->tenant, ['symfony']), 'body', 'key'))->toBe(['method' => 'Review carefully.', 'stack-symfony' => 'Mind the voters.'])
        // A tenant that has no such guide has no such stack, on the repository
        // or in what a run asks for.
        ->and(ReviewStack::resolve($other))->toBe(['laravel'])
        ->and(ReviewStack::resolve($other, 'Laravel; symfony'))->toBe(['laravel']);
});

it('lets the platform, and nobody else, change how a review works', function () {
    $files = fn (): array => array_column((array) ReviewInstructions::files($this->repository->tenant, []), 'body', 'key');

    // As it ships: the structure is the scripts' own file, and no part of what is served.
    expect($files())->toBe(['method' => 'Review carefully.'])
        ->and(ReviewInstructions::base(ReviewInstructions::STRUCTURE))->toBeNull()
        ->and(ReviewInstructions::shipped(ReviewInstructions::STRUCTURE))->toBe(File::get(resource_path('review/skill/SKILL.md')))
        ->and(ReviewInstructions::shipped('method'))->toContain('# Pull request review')
        ->and(ReviewInstructions::shipped('stack-of-our-own'))->toBeNull();

    // The platform's copy starts as the shipped text, and goes to every review once it is there.
    $copy = ReviewInstructions::customiseBase(ReviewInstructions::STRUCTURE);
    expect($copy->body)->toBe(File::get(resource_path('review/skill/SKILL.md')))
        ->and(ReviewInstructions::customiseBase(ReviewInstructions::STRUCTURE)->is($copy))->toBeTrue();

    $copy->update(['body' => 'Our own flow.']);
    // A tenant's row of the same name is not the structure.
    ReviewInstruction::query()->create(['tenant_id' => $this->repository->tenant_id, 'key' => 'skill', 'title' => 'Ours', 'position' => 5, 'body' => 'A tenant rewrites the report.']);

    expect($files())->toBe(['skill' => 'Our own flow.', 'method' => 'Review carefully.'])
        ->and(ReviewInstructions::defaultsWithoutCopy($this->repository->tenant)->pluck('key')->all())->not->toContain('skill');

    // The security review's prompt is the platform's too, and no part of a pull request's review.
    ReviewInstructions::customiseBase(ReviewInstructions::SECURITY)->update(['body' => 'Our own security prompt.']);

    expect($files())->not->toHaveKey('security')
        ->and(ReviewInstructions::base(ReviewInstructions::SECURITY))->toBe('Our own security prompt.');
});
