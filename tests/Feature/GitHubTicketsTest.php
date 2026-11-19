<?php

use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use App\Models\Repository;
use App\Tickets\GitHubIssuesDriver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->connection = fn (array $settings = []): Connection => Connection::factory()->github()->create(['tenant_id' => $this->repository->tenant_id, 'settings' => $settings]);
});

it('reads an issue from GitHub\'s own API', function () {
    Http::fake(['api.github.com/*' => Http::response(['number' => 12, 'title' => 'Fix the cart', 'body' => 'It is empty.', 'html_url' => 'https://github.com/acme/shop/issues/12', 'comments' => 0])]);

    $ticket = (new GitHubIssuesDriver)->fetch(($this->connection)(), '#12', $this->repository);

    expect($ticket->markdown)->toContain('Fix the cart')
        ->and(Http::recorded()[0][0]->url())->toBe('https://api.github.com/repos/acme/shop/issues/12');
});

it('follows no redirect from an address of the tenant\'s own, and does not read the notice as an issue', function () {
    Http::fake(['github.example.com/*' => Http::response(['message' => 'Moved Permanently'], 301, ['Location' => 'http://10.0.0.5/issues/12'])]);

    expect(fn () => (new GitHubIssuesDriver)->fetch(($this->connection)(['api_url' => 'https://github.example.com/api/v3']), '#12', $this->repository))
        ->toThrow(ConnectionFailed::class, 'redirects to http://10.0.0.5/issues/12');

    // Only the address that was entered was asked.
    Http::assertSentCount(1);
});
