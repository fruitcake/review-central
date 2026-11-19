<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Enums\BuildState;
use LogicException;

/**
 * For a host this application only reads from: it clones the code and reads a
 * pull request where the host has them, and writes nothing there. A review of
 * such a repository is kept here, on its own page, and is not posted.
 *
 * What shows a review's progress on the host is quietly left out. What would put
 * words on the host under somebody's name is a mistake to call: the caller asks
 * {@see SourceControl::publishes()} first.
 */
trait ReadsOnly
{
    public function publishes(): bool
    {
        return false;
    }

    public function accountId(): ?string
    {
        return null;
    }

    public function addPullRequestComment(string $id, string $content, ?string $path = null, ?int $line = null, ?int $parentId = null): array
    {
        throw $this->readOnly();
    }

    public function updatePullRequestComment(string $id, int $commentId, string $content): array
    {
        throw $this->readOnly();
    }

    public function resolvePullRequestComment(string $id, int $commentId, bool $resolved = true): array
    {
        throw $this->readOnly();
    }

    public function getPullRequestTasks(string $id): array
    {
        return [];
    }

    public function createPullRequestTask(string $id, string $content, ?int $commentId = null): array
    {
        throw $this->readOnly();
    }

    public function resolvePullRequestTask(string $id, int $taskId, string $content): array
    {
        throw $this->readOnly();
    }

    public function updatePullRequestDescription(string $id, string $title, string $description): int
    {
        throw $this->readOnly();
    }

    public function approve(string $id, bool $approved): int
    {
        throw $this->readOnly();
    }

    public function requestChanges(string $id, bool $requested): int
    {
        throw $this->readOnly();
    }

    public function setBuildStatus(string $commit, BuildState $state, string $url, string $description): void
    {
        // Nothing of the review is shown on a host it only reads.
    }

    public function putCommitReport(string $commit, array $report): void
    {
        // As above.
    }

    public function addCommitReportAnnotations(string $commit, array $annotations): ?int
    {
        return null;
    }

    protected function readOnly(): LogicException
    {
        return new LogicException('This host is only read from here: a review of its repositories is kept on its own page and not posted.');
    }
}
