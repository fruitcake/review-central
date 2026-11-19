<?php

use App\SourceControl\BitbucketClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

it('asks Bitbucket again when it drops the connection, for what is safe to ask twice', function () {
    Http::fake(['api.bitbucket.org/*' => Http::sequence()
        ->pushFailedConnection('cURL error 56: Recv failure: Connection reset by peer')
        ->push([], 200)]);

    $status = (new BitbucketClient('acme', 'shop', 'token'))->updatePullRequestDescription('47', 'Title', 'Description');

    expect($status)->toBe(200);
    Http::assertSentCount(2);
});

it('does not post a comment twice when the connection drops: the first may have arrived', function () {
    Http::fake(['api.bitbucket.org/*' => Http::sequence()
        ->pushFailedConnection('cURL error 56: Recv failure: Connection reset by peer')
        ->push(['id' => 1], 201)]);

    expect(fn () => (new BitbucketClient('acme', 'shop', 'token'))->addPullRequestComment('47', 'Looks wrong.'))
        ->toThrow(ConnectionException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    Http::assertSentCount(1);
});

it('does not ask again after a time-out: a slow Bitbucket stays slow', function () {
    Http::fake(['api.bitbucket.org/*' => Http::sequence()
        ->pushFailedConnection('cURL error 28: Operation timed out after 20001 milliseconds')
        ->push([], 200)]);

    expect(fn () => (new BitbucketClient('acme', 'shop', 'token'))->updatePullRequestDescription('47', 'Title', 'Description'))
        ->toThrow(ConnectionException::class);

    Http::assertSentCount(1);
});
