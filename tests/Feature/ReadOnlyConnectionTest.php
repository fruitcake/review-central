<?php

use App\Enums\BuildState;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewFiling;
use App\Services\ReviewQuestions;
use App\SourceControl\ReadOnlyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/7' => Http::response(['id' => 7, 'title' => 'Export', 'state' => 'OPEN']),
        'api.bitbucket.org/*' => Http::response([]),
    ]);

    $this->repository = Repository::factory()->create(['workspace' => 'acme', 'slug' => 'shop']);
});

it('posts on Bitbucket unless the connection keeps its reviews here', function () {
    expect($this->repository->sourceControl())->not->toBeInstanceOf(ReadOnlyClient::class)
        ->and($this->repository->sourceControl()->publishes())->toBeTrue();

    $this->repository->integration->update(['settings' => ['workspace' => 'acme', ReadOnlyClient::SETTING => ReadOnlyClient::READ]]);
    $client = $this->repository->refresh()->sourceControl();

    // It still reads the pull request through Bitbucket.
    expect($client)->toBeInstanceOf(ReadOnlyClient::class)
        ->and($client->publishes())->toBeFalse()
        ->and($client->supportsTasks())->toBeFalse()
        ->and($client->getPullRequest('7'))->toMatchArray(['id' => 7, 'title' => 'Export']);

    // And writes nothing there: a status is left out, a comment refused.
    $client->setBuildStatus(str_repeat('a', 40), BuildState::InProgress, 'https://review.test/run', 'Reviewing');

    expect(fn () => $client->addPullRequestComment('7', 'A finding'))->toThrow(LogicException::class)
        ->and(app(ReviewQuestions::class)->reply($this->repository, 7, 77, 'An answer'))->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT', 'DELETE'], true));
});

it('files the review of a connection that keeps them here as the answer for its commit, posting nothing', function () {
    $this->repository->integration->update(['settings' => ['workspace' => 'acme', ReadOnlyClient::SETTING => ReadOnlyClient::READ]]);

    $filed = app(ReviewFiling::class)->file($this->repository->refresh(), [
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'build' => 41, 'pull_request_id' => 7,
        'result' => 'PASSED', 'summary' => 'Fine.', 'findings' => [], 'publish' => false,
    ]);

    $run = ReviewRun::query()->where('build_number', 41)->sole();

    expect($run->status)->toBe(ReviewRun::STATUS_FINISHED)
        ->and($filed['publish']['reason'])->toBe('the connection keeps its reviews here');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT', 'DELETE'], true));
});
