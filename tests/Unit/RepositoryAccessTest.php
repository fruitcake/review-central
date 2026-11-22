<?php

use App\Services\RepositoryAccess;

it('reads workspace and slug from whatever someone pastes for a repository', function (string $name, ?array $expected) {
    expect(RepositoryAccess::fullName($name))->toBe($expected);
})->with([
    'workspace/slug' => ['acme/shop', ['acme', 'shop']],
    'an SSH remote' => ['git@github.com:acme/shop.git', ['acme', 'shop']],
    'an HTTPS remote' => ['https://bitbucket.org/acme/shop.git', ['acme', 'shop']],
    'a remote with a user' => ['https://dev@bitbucket.org/acme/shop.git', ['acme', 'shop']],
    'a GitHub pull request' => ['https://github.com/acme/shop/pull/12', ['acme', 'shop']],
    'a Bitbucket pull request' => ['https://bitbucket.org/acme/shop/pull-requests/12/overview', ['acme', 'shop']],
    'a file on a branch' => ['https://github.com/acme/shop/blob/main/app/User.php', ['acme', 'shop']],
    'a slug alone' => ['shop', null],
    'a path too deep' => ['acme/team/shop', null],
]);
