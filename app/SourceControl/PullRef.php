<?php

declare(strict_types=1);

namespace App\SourceControl;

/**
 * The branch name a pull request from a fork goes by here: the ref its host keeps
 * the pull request's head under in this repository, GitHub's pull/<number>/head.
 *
 * A fork's own branch name says nothing in this repository: a contributor's
 * `main` is not this repository's `main`. Under this name the pull request is
 * cloned, locked, recorded and kept a session for as itself, and the name says
 * which pull request it is.
 */
final class PullRef
{
    public static function name(int $pullRequestId): string
    {
        return "pull/{$pullRequestId}/head";
    }

    /**
     * The pull request a branch name is the pull ref of, or null when it is a branch.
     */
    public static function id(string $branch): ?int
    {
        return preg_match('#^pull/([1-9][0-9]{0,9})/head$#', $branch, $match) === 1 ? (int) $match[1] : null;
    }
}
