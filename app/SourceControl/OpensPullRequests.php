<?php

declare(strict_types=1);

namespace App\SourceControl;

use Illuminate\Http\Client\RequestException;

/**
 * A host a person can open a pull request on, with their own token: what an
 * agent does for them through `create-pull-request`.
 */
interface OpensPullRequests
{
    /**
     * Opens a pull request of a pushed branch.
     *
     * @param  string|null  $destination  the branch to merge into; null for the repository's main branch
     * @return array{id: int, url: string}
     *
     * @throws RequestException when the host refuses it
     */
    public function openPullRequest(string $branch, ?string $destination, string $title, string $description, bool $draft): array;
}
