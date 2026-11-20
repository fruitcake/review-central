<?php

declare(strict_types=1);

namespace App\SourceControl;

use Filament\Forms\Components\Select;

/**
 * A host's client that only reads: for a connection set to keep its reviews here
 * rather than post them, whatever its token may do. It clones and reads the pull
 * request through the host's own client, and writes nothing there: no comment,
 * no status, no approval.
 */
final class ReadOnlyClient implements SourceControl
{
    use ReadsOnly;

    /** The connection's setting that says whether it posts, and the value that says it only reads. */
    public const SETTING = 'posting';

    public const READ = 'read';

    public function __construct(protected SourceControl $client) {}

    /**
     * The field a connection says it with, for the hosts that can post.
     */
    public static function field(): Select
    {
        return Select::make(self::SETTING)
            ->label('Reviews')
            ->options(['post' => 'Post them on the pull request', self::READ => 'Keep them here, and post nothing'])
            ->placeholder('Post them on the pull request')
            ->helperText('Kept here, a review is on its own page only: no comment, status, task or approval on the host, whatever the token may do. A token that may only read is then enough. A question asked on the pull request is answered on the review\'s page.');
    }

    /** The client it reads through. */
    public function inner(): SourceControl
    {
        return $this->client;
    }

    public function getPullRequest(string $id): ?array
    {
        return $this->client->getPullRequest($id);
    }

    public function findOpenPullRequestByBranch(string $branch): ?array
    {
        return $this->client->findOpenPullRequestByBranch($branch);
    }

    public function getPullRequestComments(string $id): array
    {
        return $this->client->getPullRequestComments($id);
    }

    public function getPullRequestTasks(string $id): array
    {
        return $this->client->getPullRequestTasks($id);
    }

    public function getCommit(string $commit): ?array
    {
        return $this->client->getCommit($commit);
    }

    public function resolveCommit(string $commit): ?string
    {
        return $this->client->resolveCommit($commit);
    }

    public function getPullRequestDiff(string $id): string
    {
        return $this->client->getPullRequestDiff($id);
    }

    public function getRepository(): ?array
    {
        return $this->client->getRepository();
    }

    public function getFile(string $path): ?string
    {
        return $this->client->getFile($path);
    }

    public function fileOnMainBranch(string $path): string|false|null
    {
        return $this->client->fileOnMainBranch($path);
    }

    public function cloneAccess(): array
    {
        return $this->client->cloneAccess();
    }

    public function repositoryUrl(): string
    {
        return $this->client->repositoryUrl();
    }

    public function pullRequestUrl(int $pullRequestId): string
    {
        return $this->client->pullRequestUrl($pullRequestId);
    }

    public function commitUrl(string $commit): string
    {
        return $this->client->commitUrl($commit);
    }
}
