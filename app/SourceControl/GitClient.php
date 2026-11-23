<?php

declare(strict_types=1);

namespace App\SourceControl;

use LogicException;

/**
 * A git repository at an address, and nothing more: no pull requests, no comments,
 * no API. It can be cloned, so a branch of it can be reviewed as it stands.
 */
class GitClient implements SourceControl
{
    use ReadsOnly;

    /**
     * @param  string  $base  the address the repositories are under, such as https://git.example.com/acme
     */
    public function __construct(
        protected string $base,
        protected string $slug,
        protected ?string $username = null,
        protected ?string $password = null,
    ) {}

    public function getPullRequest(string $id): ?array
    {
        return null;
    }

    public function findOpenPullRequestByBranch(string $branch): ?array
    {
        return null;
    }

    public function openPullRequests(): array
    {
        return [];
    }

    public function getPullRequestComments(string $id): array
    {
        return [];
    }

    public function getCommit(string $commit): ?array
    {
        return null;
    }

    public function resolveCommit(string $commit): ?string
    {
        return null;
    }

    public function getPullRequestDiff(string $id): string
    {
        throw new LogicException('A git server has no pull requests, so there is no diff to review. Its repositories need Docker or a Cloudflare sandbox, for the security review.');
    }

    public function getRepository(): ?array
    {
        return null;
    }

    public function getFile(string $path): ?string
    {
        return null;
    }

    public function fileOnMainBranch(string $path): string|false|null
    {
        return null;
    }

    public function cloneAccess(): array
    {
        return [
            'url' => rtrim($this->base, '/')."/{$this->slug}.git",
            'username' => $this->password === null ? null : ($this->username ?? 'git'),
            'password' => $this->password,
        ];
    }

    public function repositoryUrl(): string
    {
        return rtrim($this->base, '/')."/{$this->slug}";
    }

    public function pullRequestUrl(int $pullRequestId): string
    {
        return rtrim($this->base, '/')."/{$this->slug}";
    }

    public function commitUrl(string $commit): string
    {
        return rtrim($this->base, '/')."/{$this->slug}";
    }
}
