<?php

use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\TicketSource;
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

it('moves a repository to where a removed copy of it is by changing places with that copy', function () {
    $token = Connection::factory()->gitHubSource()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => ['workspace' => 'acme']]);
    $app = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'acme']]);
    $live = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $token->id, 'workspace' => 'acme', 'slug' => 'site']);
    $removed = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $app->id, 'workspace' => 'acme', 'slug' => 'site']);
    $removed->delete();

    app(RepositorySetup::class)->update($live, ['connection_id' => $app->id, 'name' => 'Site']);

    expect($live->refresh()->only(['connection_id', 'slug', 'name']))->toBe(['connection_id' => $app->id, 'slug' => 'site', 'name' => 'Site'])
        ->and($live->trashed())->toBeFalse()
        ->and(Repository::withTrashed()->find($removed->id)->only(['connection_id', 'slug']))->toBe(['connection_id' => $token->id, 'slug' => 'site'])
        ->and(Repository::withTrashed()->find($removed->id)->trashed())->toBeTrue();
});

it('brings back the removed copy on the chosen connection before one on another', function () {
    $token = Connection::factory()->gitHubSource()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => ['workspace' => 'acme']]);
    $app = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'acme']]);
    $onToken = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $token->id, 'workspace' => 'acme', 'slug' => 'site']);
    $onApp = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $app->id, 'workspace' => 'acme', 'slug' => 'site']);
    $onToken->delete();
    $onApp->delete();

    $back = app(RepositorySetup::class)->add($onApp->tenant, $app, ['slug' => 'site', 'review_stack' => []]);

    expect($back->id)->toBe($onApp->id)
        ->and($onToken->refresh()->trashed())->toBeTrue();
});

it('moves a repository only to a connection that reaches its owner', function () {
    $any = Connection::factory()->gitHubSource()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => []]);
    $acme = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'Acme']]);
    $other = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'github-app', 'settings' => ['workspace' => 'other-org']]);

    expect($any->reachesOwner('other-org'))->toBeTrue()
        ->and($acme->reachesOwner('acme'))->toBeTrue()
        ->and($other->reachesOwner('acme'))->toBeFalse();
});

it('keeps a ticket source for a removed repository for that repository', function () {
    $source = TicketSource::factory()->create(['tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id]);
    $this->repository->delete();

    expect($source->refresh()->repository?->slug)->toBe('shop');
});
