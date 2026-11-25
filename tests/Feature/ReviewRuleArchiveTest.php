<?php

use App\Enums\ReviewRuleKind;
use App\Jobs\CheckReviewRules;
use App\Llm\Agents\RuleMatcher;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRule;
use App\Services\ReviewRequest;
use App\Services\ReviewRuleArchive;
use App\Services\ReviewRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);

    $this->rule = fn (string $title, array $attributes = []): ReviewRule => ReviewRule::factory()->create($attributes + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'kind' => ReviewRuleKind::Check, 'title' => $title,
    ]);

    $this->bitbucket = fn (array $listing = []) => Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/.ai/rules/*' => Http::response(['values' => $listing]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/.ai/rules/exports.md' => Http::response("---\npaths:\n  - \"app/Export*.php\"\n---\n\n# Every export is scoped to the company\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/.ai/rules/index.md' => Http::response('# Project Rules Index'),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/*' => Http::response('', 404),
    ]);
});

it('turns off the repository\'s rules its main branch holds in a rule file, and leaves the rest', function () {
    ($this->bitbucket)([
        ['type' => 'commit_file', 'path' => '.ai/rules/exports.md'],
        ['type' => 'commit_file', 'path' => '.ai/rules/index.md'],
        ['type' => 'commit_directory', 'path' => '.ai/rules/boost'],
    ]);
    $moved = ($this->rule)('Every export is scoped to the company');
    $kept = ($this->rule)('Jobs are idempotent');
    $elsewhere = ($this->rule)('Prices are in cents');
    // For every repository of the tenant: it holds for the others still.
    $everywhere = ($this->rule)('Exports are queued', ['repository_id' => null]);

    RuleMatcher::fake([['rules' => [
        ['id' => (string) $moved->id, 'verdict' => 'in_repository', 'file' => '.ai/rules/exports.md', 'note' => 'The file says the same.'],
        ['id' => (string) $kept->id, 'verdict' => 'not_found', 'file' => '', 'note' => 'No file says it.'],
        // A file the model was not shown holds nothing.
        ['id' => (string) $elsewhere->id, 'verdict' => 'in_repository', 'file' => '.ai/rules/prices.md', 'note' => 'Made up.'],
    ]]]);

    $result = app(ReviewRuleArchive::class)->check($this->repository);

    expect($result['archived'])->toBe([['id' => $moved->id, 'title' => 'Every export is scoped to the company', 'file' => '.ai/rules/exports.md', 'note' => 'The file says the same.']])
        ->and($result['checked'])->toBe(3)
        ->and($moved->refresh()->only(['enabled', 'archived_to', 'archived_note']))->toBe(['enabled' => false, 'archived_to' => '.ai/rules/exports.md', 'archived_note' => 'The file says the same.'])
        ->and($moved->archived_at)->not->toBeNull()
        ->and($kept->refresh()->enabled)->toBeTrue()
        ->and($elsewhere->refresh()->enabled)->toBeTrue()
        ->and($everywhere->refresh()->enabled)->toBeTrue()
        // The review no longer reads it from the list.
        ->and(ReviewRules::for($this->repository)->modelKeys())->not->toContain($moved->id);

    // The index is no rule, and the rules for every repository are not asked about.
    RuleMatcher::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '## .ai/rules/exports.md')
        && ! str_contains($prompt->prompt, 'Project Rules Index')
        && ! str_contains($prompt->prompt, 'Exports are queued'));
});

it('asks the model again only when the rule files or the rules changed', function () {
    ($this->bitbucket)([['type' => 'commit_file', 'path' => '.ai/rules/exports.md']]);
    $rule = ($this->rule)('Jobs are idempotent');
    $answer = ['rules' => [['id' => (string) $rule->id, 'verdict' => 'not_found', 'file' => '', 'note' => 'No file says it.']]];
    RuleMatcher::fake([$answer, $answer, $answer]);

    app(ReviewRuleArchive::class)->check($this->repository);
    $again = app(ReviewRuleArchive::class)->check($this->repository);

    expect($again['note'])->toBe('Neither the rule files on the main branch nor the rules changed since the last check.');
    RuleMatcher::assertPromptedTimes(1);

    // Asked for by a person, it checks all the same; a changed rule is checked again too.
    app(ReviewRuleArchive::class)->check($this->repository, always: true);
    $this->travel(1)->minute();
    $rule->update(['body' => 'A retried job does its work once.']);
    app(ReviewRuleArchive::class)->check($this->repository);

    RuleMatcher::assertPromptedTimes(3);
});

it('leaves a rule on that a person turned on again after it was found', function () {
    ($this->bitbucket)([['type' => 'commit_file', 'path' => '.ai/rules/exports.md']]);
    $rule = ($this->rule)('Every export is scoped to the company', ['enabled' => false, 'archived_at' => now(), 'archived_to' => '.ai/rules/exports.md']);

    $rule->update(['enabled' => true]);
    RuleMatcher::fake()->preventStrayPrompts();

    expect($rule->refresh()->archived_at)->toBeNull()
        ->and($rule->archived_to)->toBe('.ai/rules/exports.md')
        ->and(app(ReviewRuleArchive::class)->check($this->repository, always: true)['note'])->toBe('No rule of this repository alone is on, so there is nothing to check.')
        ->and($rule->refresh()->enabled)->toBeTrue();
});

it('turns nothing off when the rule files cannot be listed', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/*' => Http::response('', 403),
    ]);
    $rule = ($this->rule)('Every export is scoped to the company');
    RuleMatcher::fake()->preventStrayPrompts();

    expect(app(ReviewRuleArchive::class)->check($this->repository)['note'])->toBe('The rule files on the main branch could not be read.')
        ->and($rule->refresh()->enabled)->toBeTrue();
});

it('lists the rule files on a GitHub main branch from its tree', function () {
    $repository = Repository::factory()->for(Connection::factory()->gitHubSource()->state(['tenant_id' => $this->repository->tenant_id]), 'integration')
        ->create(['tenant_id' => $this->repository->tenant_id, 'workspace' => 'acme', 'slug' => 'app']);
    Http::fake([
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'trunk']),
        'api.github.com/repos/acme/app/git/trees/trunk*' => Http::response(['truncated' => false, 'tree' => [
            ['type' => 'blob', 'path' => '.ai/rules/exports.md'],
            ['type' => 'blob', 'path' => '.github/instructions/php.instructions.md'],
            ['type' => 'tree', 'path' => '.ai/rules'],
            ['type' => 'blob', 'path' => '.ai/rulesets.md'],
            ['type' => 'blob', 'path' => 'app/Export.php'],
        ]]),
    ]);

    expect($repository->sourceControl()->filesOnMainBranch(ReviewRuleArchive::DIRECTORIES))
        ->toBe(['.ai/rules/exports.md', '.github/instructions/php.instructions.md']);
});

it('queues the check when a pull request is merged into a repository with rules of its own on', function () {
    Queue::fake();
    Http::fake(['*' => Http::response([])]);

    app(ReviewRequest::class)->sync($this->repository, 570, ['id' => 570, 'state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('m', 40)]]);
    Queue::assertNotPushed(CheckReviewRules::class);

    ($this->rule)('Jobs are idempotent');
    app(ReviewRequest::class)->sync($this->repository, 571, ['id' => 571, 'state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('n', 40)]]);
    Queue::assertPushed(CheckReviewRules::class, fn (CheckReviewRules $job): bool => $job->repositoryId === $this->repository->id);
});
