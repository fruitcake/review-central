<?php

use App\Exceptions\ConnectionFailed;
use App\Exceptions\TicketNotFound;
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

it('reads no pull request as an issue', function () {
    Http::fake(['api.github.com/*' => Http::response(['number' => 12, 'title' => 'Refactor the cart', 'body' => 'Internal notes.', 'comments' => 3, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/shop/pulls/12']])]);

    expect(fn () => (new GitHubIssuesDriver)->fetch(($this->connection)(), '#12', $this->repository))
        ->toThrow(TicketNotFound::class);

    // Its conversation was not read either.
    Http::assertSentCount(1);
});

it('lists the images GitHub keeps for an issue and its comments, and reads them without the token', function () {
    $signed = 'https://private-user-images.githubusercontent.com/1/shot.png?jwt=signed';
    Http::fake([
        'api.github.com/repos/acme/shop/issues/12/comments*' => Http::response([
            ['user' => ['login' => 'sam'], 'body' => 'Here too.', 'html_url' => 'https://github.com/acme/shop/issues/12#c1', 'body_html' => '<p><img src="https://github.com/user-attachments/assets/abc" alt="Second shot"></p>'],
        ]),
        'api.github.com/repos/acme/shop/issues/12' => Http::response([
            'number' => 12, 'title' => 'Fix the cart', 'body' => 'See ![shot](x)', 'html_url' => 'https://github.com/acme/shop/issues/12', 'comments' => 1,
            // Signed addresses in the HTML; an image elsewhere and a tracker pixel are not GitHub's.
            'body_html' => '<p><img src="'.htmlspecialchars($signed).'" alt="shot"> <img src="http://169.254.169.254/latest/meta-data"> <img src="https://evil.example/pixel.png" alt="pixel"></p>',
        ]),
        'private-user-images.githubusercontent.com/*' => Http::response('PNG', 200, ['Content-Type' => 'image/png']),
    ]);

    $driver = new GitHubIssuesDriver;
    $connection = ($this->connection)();
    $ticket = $driver->fetch($connection, '#12', $this->repository);

    expect(array_map(fn ($attachment): array => [$attachment->name, $attachment->downloadUrl], $ticket->attachments))->toBe([
        ['shot', $signed],
        ['Second shot', 'https://github.com/user-attachments/assets/abc'],
    ])
        ->and($ticket->markdown)->toContain("## Attachments\n\n1. shot\n2. Second shot")
        ->and($driver->download($connection, $ticket->attachments[0])->contents)->toBe('PNG');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/issues/12') && $request->header('Accept') === ['application/vnd.github.full+json']);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'githubusercontent.com') && $request->hasHeader('Authorization'));
});
