<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Enums\BuildState;
use Illuminate\Http\Client\RequestException;

/**
 * One repository on its host, as the review reads and writes it.
 *
 * Pull requests, comments and tasks are passed around in the shape Bitbucket gives
 * them, which is what the review's context is built from. A driver for another
 * host, such as GitHub's, maps its own payloads to that shape:
 *
 *  - pull request: id, title, description, state (OPEN, MERGED, DECLINED), draft,
 *    updated_on, source.branch.name, source.commit.hash, destination.branch.name,
 *    author.display_name, author.account_id, author.nickname, links.html.href
 *  - comment: id, content.raw, created_on, updated_on, deleted, user.display_name,
 *    user.account_id, user.nickname, inline.path, inline.to, parent.id, resolution
 *  - task: id, state (UNRESOLVED, RESOLVED), content.raw, comment.id
 *
 * Calls that read throw on a failed request rather than return a partial answer:
 * a partial comment list reads as "no previous review".
 */
interface SourceControl
{
    /**
     * Whether a review is posted on this host: its comments, its verdict, its
     * status on a commit. Where it is not, the host is only read from and the
     * review is kept on its own page.
     */
    public function publishes(): bool;

    /**
     * Whether the host has tasks on a pull request, which the review opens for
     * what blocks the merge. One without, such as GitHub, is held by the request
     * for changes alone.
     */
    public function supportsTasks(): bool;

    /**
     * The account the connection acts as, in the form a comment mentions it by, or
     * null when the host does not say.
     */
    public function accountId(): ?string;

    /**
     * @return array<string, mixed>|null null when there is no such pull request
     */
    public function getPullRequest(string $id): ?array;

    /**
     * @return array<string, mixed>|null the open pull request from a branch, the newest when there are several
     */
    public function findOpenPullRequestByBranch(string $branch): ?array;

    /**
     * Every open pull request, as getPullRequest() gives each; none for a host
     * without pull requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openPullRequests(): array;

    /**
     * Whether the pull request conflicts with its destination, with the files
     * where the host names them; null while the host is still working it out,
     * or for a host that cannot say.
     *
     * @return array{conflicts: bool, files: array<int, string>}|null
     */
    public function mergeConflicts(string $id): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPullRequestComments(string $id): array;

    /**
     * A general comment, an inline one when a path is given, or a reply when a
     * parent is.
     *
     * @return array<string, mixed>
     */
    public function addPullRequestComment(string $id, string $content, ?string $path = null, ?int $line = null, ?int $parentId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequestComment(string $id, int $commentId, string $content): array;

    /**
     * Resolves a comment thread, or reopens it.
     *
     * @return array<string, mixed>
     */
    public function resolvePullRequestComment(string $id, int $commentId, bool $resolved = true): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPullRequestTasks(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function createPullRequestTask(string $id, string $content, ?int $commentId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function resolvePullRequestTask(string $id, int $taskId, string $content): array;

    /**
     * @return int the HTTP status, which the caller reads rather than an exception
     */
    public function updatePullRequestDescription(string $id, string $title, string $description): int;

    /**
     * Approves the pull request, or withdraws the approval.
     *
     * @return int the HTTP status
     */
    public function approve(string $id, bool $approved): int;

    /**
     * Requests changes, or withdraws the request.
     *
     * @return int the HTTP status
     */
    public function requestChanges(string $id, bool $requested): int;

    /**
     * @return array{message: string, author: string}|null
     */
    public function getCommit(string $commit): ?array;

    /**
     * The full hash of a commit that may be named by the start of it, as a pull
     * request names its head. Null when the host does not know it.
     */
    public function resolveCommit(string $commit): ?string;

    /**
     * The diff of a whole pull request against its destination, as unified diff text.
     *
     * @throws RequestException
     */
    public function getPullRequestDiff(string $id): string;

    /**
     * The repository as its host describes it, or null when the token cannot see it.
     *
     * @return array<string, mixed>|null
     */
    public function getRepository(): ?array;

    /**
     * A file on the repository's main branch, or null when it has none there.
     */
    public function getFile(string $path): ?string;

    /**
     * A file on the repository's main branch; false when the branch has no such
     * file, which is only said once the repository itself is known to be readable;
     * null when it could not be read, such as when access is refused.
     */
    public function fileOnMainBranch(string $path): string|false|null;

    /**
     * Shows a review among the builds of a commit, which is where a pull request
     * says that one is running and how it ended. The same call again replaces it.
     *
     * @throws RequestException
     */
    public function setBuildStatus(string $commit, BuildState $state, string $url, string $description): void;

    /**
     * Puts the review's report on a commit, where the pull request lists it beside
     * its builds. The same call again replaces it. In the shape Bitbucket's Code
     * Insights takes: title, details, report_type, reporter, result, data.
     *
     * @param  array<string, mixed>  $report
     *
     * @throws RequestException
     */
    public function putCommitReport(string $commit, array $report): void;

    /**
     * Adds findings to that report as annotations in the diff, at most 100 a call.
     *
     * @param  list<array<string, mixed>>  $annotations
     * @return int|null how many annotations the report now holds, when the host says
     *
     * @throws RequestException
     */
    public function addCommitReportAnnotations(string $commit, array $annotations): ?int;

    /**
     * What a clone of the repository needs, for a runner that checks it out itself.
     *
     * @return array{url: string, username: string|null, password: string|null} no user name and password for a repository anyone can clone
     */
    public function cloneAccess(): array;

    /** Where a person opens the repository on its host. */
    public function repositoryUrl(): string;

    public function pullRequestUrl(int $pullRequestId): string;

    public function commitUrl(string $commit): string;

    /**
     * Where a person reads a file as it was at a commit, at a line when one is
     * given; null for a host that shows no files.
     */
    public function fileUrl(string $commit, string $path, ?int $line = null): ?string;
}
