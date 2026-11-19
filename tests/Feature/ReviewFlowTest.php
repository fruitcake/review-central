<?php

use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Models\ReviewRun;
use App\Models\TicketSource;
use App\Services\ReviewInstructions;
use App\Services\ReviewStack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->sha = str_repeat('a', 40);
    $this->repository = $this->actAsRunOf(Repository::factory()->create(['slug' => 'shop']), 'key-one');

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

function startReview(string $key = 'key-one', array $input = []): TestResponse
{
    return test()->postJson('/api/review/review-context', array_merge([
        'pull_request_id' => 570,
        'branch' => 'feature/export',
        'commit' => test()->sha,
        'lock' => '77',
        'instructions' => '1',
    ], $input), ['X-Api-Key' => $key]);
}

it('refuses a call without a repository key', function () {
    $this->postJson('/api/review/review-context', ['pull_request_id' => 570])->assertForbidden();
    $this->postJson('/api/review/review-context', ['pull_request_id' => 570], ['X-Api-Key' => 'wrong'])->assertForbidden();
});

it('hands the pipeline the pull request, the ticket and the instructions, and opens the run for the tenant', function () {
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

    $this->postJson('/api/review/review-report', [
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
    ], ['X-Api-Key' => 'key-one'])->assertOk()->assertJsonPath('publish.published', true);

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

it('keeps one tenant\'s key away from another tenant\'s repository', function () {
    fakeBitbucket();

    $other = $this->actAsRunOf(Repository::factory()->create(['slug' => 'other']), 'key-two');

    Http::fake(['api.bitbucket.org/2.0/repositories/acme/other/*' => Http::response([], 404)]);

    // The repository comes from the key: asking for pull request 570 with the
    // other tenant's key looks in that tenant's repository, and finds nothing.
    startReview('key-two')->assertNotFound();

    expect(ReviewRun::query()->count())->toBe(0);
    expect($other->tenant_id)->not->toBe($this->repository->tenant_id);
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

it('says so when the repository\'s host refuses the connection\'s credentials', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response(['error' => ['message' => 'Token is invalid']], 401)]);

    startReview()
        ->assertStatus(502)
        ->assertJsonPath('message', "The repository's host answered 401. Check the credentials of the repository's connection.");
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

it('carries the two files an installation had over into one, for the defaults and for a tenant\'s own', function () {
    $migration = require database_path('migrations/2026_11_19_150000_simplify_review_instructions.php');
    $tenant = $this->repository->tenant_id;
    $other = Repository::factory()->create();
    $third = Repository::factory()->create();

    // As it was before: two files, drafts, and a channel per repository.
    $migration->down();
    ReviewInstruction::query()->delete();
    DB::table('review_instructions')->insert([
        ['tenant_id' => null, 'key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => "# Method\n\nJudge carefully.", 'draft_body' => 'An unpublished rewrite.'],
        ['tenant_id' => null, 'key' => 'defect-lenses', 'title' => 'Defect lenses', 'position' => 20, 'body' => "# Defect lenses\n\nLook at both sides of a call.", 'draft_body' => null],
        // A tenant that kept its own lenses and read the default method.
        ['tenant_id' => $tenant, 'key' => 'defect-lenses', 'title' => 'Defect lenses', 'position' => 20, 'body' => 'Ours: look at the company scope.', 'draft_body' => null],
        // Another tenant that kept its own method and read the default lenses.
        ['tenant_id' => $other->tenant_id, 'key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => 'Theirs: judge by the book.', 'draft_body' => null],
        // A third wrote a method of its own and never published it: its reviews read the default.
        ['tenant_id' => $third->tenant_id, 'key' => 'method', 'title' => 'Method', 'position' => 10, 'body' => null, 'draft_body' => 'Half a method.'],
        // Never published: no review ever read it.
        ['tenant_id' => null, 'key' => 'stack-symfony', 'title' => 'Symfony', 'position' => 30, 'body' => null, 'draft_body' => 'A guide in the making.'],
    ]);

    $migration->up();

    $bodies = fn (?int $of) => ReviewInstruction::query()->where('tenant_id', $of)->pluck('body', 'key')->all();

    expect($bodies(null))->toBe(['method' => "# Method\n\nJudge carefully.\n\nDEFECT LENSES:\n\nLook at both sides of a call."])
        ->and($bodies($tenant))->toBe(['method' => "# Method\n\nJudge carefully.\n\nDEFECT LENSES:\n\nOurs: look at the company scope."])
        // Its own method, and the lenses it read beside it.
        ->and($bodies($other->tenant_id))->toBe(['method' => "Theirs: judge by the book.\n\nDEFECT LENSES:\n\nLook at both sides of a call."])
        // Nothing of its own is left, so it goes on reading the default, lenses and all.
        ->and($bodies($third->tenant_id))->toBe([])
        ->and(Schema::hasColumn('review_instructions', 'draft_body'))->toBeFalse()
        ->and(Schema::hasColumn('repositories', 'review_instructions_channel'))->toBeFalse();
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
