<?php

use App\SourceControl\GitHubClient;
use Illuminate\Support\Facades\Http;

it('reads every page of a GitHub pull request\'s reviews, where the newest are last', function () {
    $comment = fn (int $id): array => ['id' => $id, 'state' => 'COMMENTED', 'user' => ['login' => 'review-bot', 'id' => 99], 'submitted_at' => '2026-11-19T09:00:00Z'];
    $first = [['id' => 1, 'state' => 'CHANGES_REQUESTED', 'user' => ['login' => 'nora', 'id' => 5], 'submitted_at' => '2026-11-18T09:00:00Z'], ...array_map($comment, range(2, 100))];

    Http::fake(['api.github.com/repos/acme/shop/pulls/7/reviews*' => Http::sequence()
        ->push($first)
        ->push([['id' => 101, 'state' => 'APPROVED', 'user' => ['login' => 'nora', 'id' => 5], 'submitted_at' => '2026-11-19T11:00:00Z']])]);

    $reviews = (new GitHubClient('acme', 'shop', 'token'))->getPullRequestReviews('7');
    $participants = GitHubClient::withReviews(['author' => []], $reviews)['participants'];

    expect($reviews)->toHaveCount(101)
        ->and(collect($participants)->firstWhere('user.nickname', 'nora')['state'])->toBe('approved');
});
