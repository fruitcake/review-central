<?php

namespace Tests;

use App\Models\Repository;
use App\Services\RunTokens;

/**
 * Run tokens for tests of what the review endpoints do: a key named here stands
 * for any run of its repository. Every other token is a real one.
 */
class AnyRunTokens extends RunTokens
{
    /** @var array<string, int> key to repository id */
    public array $repositories = [];

    public function repositoryId(string $token): ?int
    {
        return $this->repositories[$token] ?? parent::repositoryId($token);
    }

    public function vouchesFor(Repository $repository, string $token, string $branch, ?string $commit, ?string $build = null): bool
    {
        return isset($this->repositories[$token])
            ? $this->repositories[$token] === $repository->getKey()
            : parent::vouchesFor($repository, $token, $branch, $commit, $build);
    }
}
