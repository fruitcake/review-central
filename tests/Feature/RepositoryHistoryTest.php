<?php

use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\RepositorySetup;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_repeat('a', 40), 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);
});

it('keeps a removed repository\'s history, and brings it back when it is added again', function () {
    $this->repository->delete();

    expect(ReviewRun::query()->whereKey($this->run->id)->exists())->toBeTrue()
        ->and($this->run->refresh()->repository->slug)->toBe('shop')
        ->and($this->repository->tenant->repositories()->count())->toBe(0);

    $again = app(RepositorySetup::class)->add($this->repository->tenant, $this->repository->integration, ['slug' => 'shop', 'review_stack' => ['laravel']]);

    expect($again->id)->toBe($this->repository->id)
        ->and($again->trashed())->toBeFalse()
        ->and($again->reviewRuns()->count())->toBe(1);
});

it('brings a removed repository back on another connection to the same host, and only the same host', function () {
    $token = Connection::factory()->gitHubSource()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => ['workspace' => 'acme']]);
    $app = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'acme']]);
    $repository = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $token->id, 'workspace' => 'acme', 'slug' => 'site']);
    $repository->delete();

    expect($token->sameHostAs($app))->toBeTrue()
        ->and($token->sameHostAs($this->repository->integration))->toBeFalse();

    $moved = app(RepositorySetup::class)->add($repository->tenant, $app, ['slug' => 'site', 'review_stack' => []]);

    expect($moved->id)->toBe($repository->id)
        ->and($moved->connection_id)->toBe($app->id);
});

it('keeps a removed source control connection for its history, without its secrets', function () {
    $connection = $this->repository->integration;
    $this->repository->delete();

    $connection->remove();

    expect(Connection::withTrashed()->find($connection->id))
        ->not->toBeNull()
        ->trashed()->toBeTrue()
        ->credentials->toBeNull()
        ->webhook_key->toBeNull()
        ->and($this->run->refresh()->repository->integration->id)->toBe($connection->id);
});

it('deletes a connection of another kind outright', function () {
    $llm = Connection::factory()->anthropic()->create();

    $llm->remove();

    expect(Connection::withTrashed()->find($llm->id))->toBeNull();
});
