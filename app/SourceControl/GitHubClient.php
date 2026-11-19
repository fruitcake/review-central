<?php

declare(strict_types=1);

namespace App\SourceControl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A repository on GitHub, read and not written to.
 *
 * A pull request and its comments are handed on in the shape the review reads
 * them in everywhere, which is Bitbucket's: a state of OPEN, MERGED or DECLINED,
 * a source and a destination with a branch and a commit. A public repository
 * needs no token; a private one a token that can read it.
 *
 * Not here yet: comments, approvals and checks on GitHub, its webhooks, and an
 * open pull request from a fork, whose branch is not in this repository.
 */
class GitHubClient implements SourceControl
{
    use ReadsOnly;

    protected const API = 'https://api.github.com';

    public function __construct(
        protected string $owner,
        protected string $name,
        protected ?string $token = null,
    ) {}

    public function getPullRequest(string $id): ?array
    {
        $response = $this->request(10)->get($this->url("pulls/{$id}"));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        $pull = $response->throw()->json();
        $pullRequest = $this->pullRequest($pull);

        if (is_array($pullRequest['merge_commit']) && $this->rebased($pull)) {
            $pullRequest['merge_commit']['rebased'] = true;
        }

        return $pullRequest;
    }

    /**
     * Whether a merged pull request of more than one commit went in by "Rebase and
     * merge". Its commits then land on the branch one by one, and the commit GitHub
     * names as the merge is only the last of them: its one parent is not where the
     * pull request began. A squash also leaves one commit with one parent, but a
     * new one; a rebase keeps each commit's message and author date, so the last
     * commit of the pull request and the "merge" match in both.
     *
     * Unknown, as when GitHub does not answer, counts as no rebase.
     *
     * @param  array<string, mixed>  $pull  as GitHub describes it
     */
    protected function rebased(array $pull): bool
    {
        $count = (int) ($pull['commits'] ?? 1);

        if ($count < 2) {
            return false;
        }

        try {
            $merge = $this->request(10)->get($this->url('commits/'.$pull['merge_commit_sha']));
            $commits = $this->request(10)->get($this->url("pulls/{$pull['number']}/commits"), ['per_page' => 100, 'page' => (int) ceil(min($count, 250) / 100)]);
        } catch (Throwable) {
            return false;
        }

        $last = $commits->successful() ? collect((array) $commits->json())->last() : null;

        if (! $merge->successful() || ! is_array($last) || count((array) $merge->json('parents')) !== 1) {
            return false;
        }

        return ($last['commit']['message'] ?? null) === $merge->json('commit.message')
            && ($last['commit']['author']['date'] ?? null) === $merge->json('commit.author.date');
    }

    public function findOpenPullRequestByBranch(string $branch): ?array
    {
        $found = $this->request(10)
            ->get($this->url('pulls'), ['state' => 'open', 'head' => "{$this->owner}:{$branch}", 'sort' => 'updated', 'direction' => 'desc', 'per_page' => 1])
            ->throw()
            ->json()[0] ?? null;

        return is_array($found) ? $this->pullRequest($found) : null;
    }

    /**
     * The remarks on the pull request and the ones on its lines, oldest first.
     */
    public function getPullRequestComments(string $id): array
    {
        $general = (array) $this->request(15)->get($this->url("issues/{$id}/comments"), ['per_page' => 100])->throw()->json();
        $inline = (array) $this->request(15)->get($this->url("pulls/{$id}/comments"), ['per_page' => 100])->throw()->json();

        return collect([...$general, ...$inline])
            ->filter(fn (mixed $comment): bool => is_array($comment) && isset($comment['id']))
            ->map(fn (array $comment): array => array_filter([
                'id' => (int) $comment['id'],
                'content' => ['raw' => (string) ($comment['body'] ?? '')],
                'user' => ['display_name' => (string) ($comment['user']['login'] ?? ''), 'account_id' => (string) ($comment['user']['id'] ?? '')],
                'created_on' => $comment['created_at'] ?? null,
                'updated_on' => $comment['updated_at'] ?? null,
                'inline' => isset($comment['path']) ? ['path' => (string) $comment['path'], 'to' => $comment['line'] ?? $comment['original_line'] ?? null] : null,
                'parent' => isset($comment['in_reply_to_id']) ? ['id' => (int) $comment['in_reply_to_id']] : null,
            ], fn (mixed $value): bool => $value !== null))
            ->sortBy('created_on')
            ->values()
            ->all();
    }

    public function getCommit(string $commit): ?array
    {
        try {
            $response = $this->request(8)->get($this->url("commits/{$commit}"));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // The subject only: a commit body can be paragraphs.
        $message = trim(strtok((string) $response->json('commit.message'), "\n") ?: '');
        $author = trim((string) ($response->json('commit.author.name') ?? ''));

        return $message === '' && $author === '' ? null : ['message' => $message, 'author' => $author];
    }

    public function resolveCommit(string $commit): ?string
    {
        $hash = rescue(fn (): mixed => $this->request(8)->get($this->url("commits/{$commit}"))->json('sha'), null, false);

        return is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? $hash : null;
    }

    public function getPullRequestDiff(string $id): string
    {
        return $this->request(60)->accept('application/vnd.github.diff')->get($this->url("pulls/{$id}"))->throw()->body();
    }

    public function getRepository(): ?array
    {
        $response = $this->request(10)->get(rtrim($this->url(''), '/'));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        $repository = $response->throw()->json();

        // Its main branch under the name the rest of the application reads it by.
        return $repository + ['mainbranch' => ['name' => $repository['default_branch'] ?? null]];
    }

    public function getFile(string $path): ?string
    {
        $response = $this->request(10)->accept('application/vnd.github.raw')
            ->get($this->url('contents/'.implode('/', array_map('rawurlencode', explode('/', $path)))));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        return $response->throw()->body();
    }

    public function cloneAccess(): array
    {
        return [
            'url' => "https://github.com/{$this->owner}/{$this->name}.git",
            // A public repository is cloned as anyone: no credential is sent.
            'username' => $this->token === null ? null : 'x-access-token',
            'password' => $this->token,
        ];
    }

    public function repositoryUrl(): string
    {
        return "https://github.com/{$this->owner}/{$this->name}";
    }

    public function pullRequestUrl(int $pullRequestId): string
    {
        return "https://github.com/{$this->owner}/{$this->name}/pull/{$pullRequestId}";
    }

    public function commitUrl(string $commit): string
    {
        return "https://github.com/{$this->owner}/{$this->name}/commit/{$commit}";
    }

    /**
     * @param  array<string, mixed>  $pull  as GitHub describes it
     * @return array<string, mixed> as the review reads a pull request
     */
    protected function pullRequest(array $pull): array
    {
        return [
            'id' => (int) ($pull['number'] ?? 0),
            'title' => (string) ($pull['title'] ?? ''),
            'description' => (string) ($pull['body'] ?? ''),
            'state' => match (true) {
                ($pull['state'] ?? null) === 'open' => 'OPEN',
                ($pull['merged'] ?? false) === true || isset($pull['merged_at']) => 'MERGED',
                default => 'DECLINED',
            },
            'draft' => (bool) ($pull['draft'] ?? false),
            'source' => ['branch' => ['name' => (string) ($pull['head']['ref'] ?? '')], 'commit' => ['hash' => (string) ($pull['head']['sha'] ?? '')]],
            'destination' => ['branch' => ['name' => (string) ($pull['base']['ref'] ?? '')], 'commit' => ['hash' => (string) ($pull['base']['sha'] ?? '')]],
            'merge_commit' => isset($pull['merge_commit_sha']) && isset($pull['merged_at']) ? ['hash' => (string) $pull['merge_commit_sha']] : null,
            'author' => ['display_name' => (string) ($pull['user']['login'] ?? '')],
            'updated_on' => $pull['updated_at'] ?? null,
            // Whether its branch is in this repository: one from a fork is not.
            'from_fork' => ($pull['head']['repo']['full_name'] ?? null) !== ($pull['base']['repo']['full_name'] ?? null),
        ];
    }

    protected function url(string $path): string
    {
        return self::API."/repos/{$this->owner}/{$this->name}/{$path}";
    }

    protected function request(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout)->connectTimeout(4)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            // GitHub refuses a request that does not say who is asking.
            ->withUserAgent('review-central');

        return $this->token === null ? $request : $request->withToken($this->token);
    }
}
