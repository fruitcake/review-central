<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Enums\BuildState;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * A repository on GitHub.
 *
 * A pull request and its comments are handed on in the shape the review reads
 * them in everywhere, which is Bitbucket's: a state of OPEN, MERGED or DECLINED,
 * a source and a destination with a branch and a commit, and comments with an
 * inline place, a parent and a resolution.
 *
 * With a token the review is posted here: its comments, an approval or a request
 * for changes as a review of GitHub's, and its state as a commit status. Without
 * one the repository is only read, which is all a public repository needs to be
 * tried. GitHub has no tasks on a pull request, and a report with annotations on
 * a commit (a check run) is for GitHub Apps only, so neither is posted: a request
 * for changes is what holds the merge.
 *
 * GitHub keeps two kinds of comment on a pull request. One on a line is part of a
 * review thread, which can be replied to and resolved. One on the pull request as
 * a whole is an issue comment, which has neither: a reply to one is another issue
 * comment that names its parent in a hidden marker, and one is resolved by
 * minimising it as resolved, which is how GitHub folds it away.
 */
class GitHubClient implements SourceControl
{
    /** Pages of 100 read before giving up on a list. */
    public const MAX_PAGES = 20;

    /** The name a review's commit status goes by; a later one of the same name replaces it. */
    public const STATUS_CONTEXT = 'AI Review';

    /** What an issue comment written as a reply says its parent is. */
    public const PARENT_MARKER = '/\[\/\/\]:\s*#\s*\(ai-reply-to (\d+)\)/';

    protected const API = 'https://api.github.com';

    /** The account the token acts as, once asked. */
    protected ?string $account = null;

    /**
     * Review threads and folded comments of a pull request, once asked: by comment.
     *
     * @var array<string, array{threads: array<int, array{id: string, resolved: bool}>, minimized: array<int, bool>}>
     */
    protected array $threads = [];

    public function __construct(
        protected string $owner,
        protected string $name,
        protected ?string $token = null,
    ) {}

    /**
     * Only with a token: without one nothing can be written, and the repository
     * is read as anyone may read it.
     */
    public function publishes(): bool
    {
        return $this->token !== null;
    }

    public function supportsTasks(): bool
    {
        return false;
    }

    /**
     * The id of the account the token acts as, which is how a comment's author is
     * told from it.
     */
    public function accountId(): ?string
    {
        if ($this->token === null) {
            return null;
        }

        return $this->account ??= rescue(fn (): ?string => is_numeric($id = $this->request(8)->get(self::API.'/user')->json('id')) ? (string) $id : null, null, false);
    }

    public function getPullRequest(string $id): ?array
    {
        $response = $this->request(10)->get($this->url("pulls/{$id}"));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        $pull = $response->throw()->json();
        $pullRequest = self::pullRequest($pull);

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
        // A pull request from a fork goes by its pull ref here, which names it.
        $id = PullRef::id($branch);

        if ($id !== null) {
            $pullRequest = $this->getPullRequest((string) $id);

            return ($pullRequest['state'] ?? null) === 'OPEN' && ($pullRequest['from_fork'] ?? false) === true ? $pullRequest : null;
        }

        $found = $this->request(10)
            ->get($this->url('pulls'), ['state' => 'open', 'head' => "{$this->owner}:{$branch}", 'sort' => 'updated', 'direction' => 'desc', 'per_page' => 1])
            ->throw()
            ->json()[0] ?? null;

        return is_array($found) ? self::pullRequest($found) : null;
    }

    /**
     * The remarks on the pull request and the ones on its lines, oldest first,
     * with whether each one's thread is resolved.
     */
    public function getPullRequestComments(string $id): array
    {
        $general = $this->all($this->url("issues/{$id}/comments"));
        $inline = $this->all($this->url("pulls/{$id}/comments"));
        $threads = $this->threads($id);

        return collect([
            ...array_map(fn (mixed $comment): mixed => is_array($comment) ? $comment + ['kind' => 'issue'] : $comment, $general),
            ...array_map(fn (mixed $comment): mixed => is_array($comment) ? $comment + ['kind' => 'review'] : $comment, $inline),
        ])
            ->filter(fn (mixed $comment): bool => is_array($comment) && isset($comment['id']))
            ->map(function (array $comment) use ($threads): array {
                $id = (int) $comment['id'];
                $body = (string) ($comment['body'] ?? '');
                $parent = $comment['in_reply_to_id'] ?? (preg_match(self::PARENT_MARKER, $body, $match) === 1 ? (int) $match[1] : null);
                $resolved = $comment['kind'] === 'review'
                    ? ($threads['threads'][$id]['resolved'] ?? false)
                    : ($threads['minimized'][$id] ?? false);

                return array_filter([
                    'id' => $id,
                    'content' => ['raw' => $body],
                    'user' => [
                        'display_name' => (string) ($comment['user']['login'] ?? ''),
                        'nickname' => (string) ($comment['user']['login'] ?? ''),
                        'account_id' => (string) ($comment['user']['id'] ?? ''),
                    ],
                    'created_on' => $comment['created_at'] ?? null,
                    'updated_on' => $comment['updated_at'] ?? null,
                    'inline' => $comment['kind'] === 'review' && isset($comment['path'])
                        ? ['path' => (string) $comment['path'], 'to' => $comment['line'] ?? $comment['original_line'] ?? null]
                        : null,
                    'parent' => $parent === null ? null : ['id' => (int) $parent],
                    'resolution' => $resolved ? ['type' => 'resolved'] : null,
                ], fn (mixed $value): bool => $value !== null);
            })
            ->sortBy('created_on')
            ->values()
            ->all();
    }

    /**
     * On a line when a path is given, as a reply when a parent is, and on the pull
     * request otherwise. A line that is not part of the diff is refused, which the
     * caller turns into a comment on the pull request.
     */
    public function addPullRequestComment(string $id, string $content, ?string $path = null, ?int $line = null, ?int $parentId = null): array
    {
        if ($parentId !== null) {
            $reply = $this->request(15)->post($this->url("pulls/{$id}/comments/{$parentId}/replies"), ['body' => $content]);

            if ($reply->successful()) {
                return (array) $reply->json();
            }

            // Not a comment in a review thread: one on the pull request, which has
            // no threads, so the reply says which comment it answers.
            return (array) $this->request(15)->post($this->url("issues/{$id}/comments"), [
                'body' => rtrim($content)."\n\n[//]: # (ai-reply-to {$parentId})",
            ])->throw()->json();
        }

        if ($path !== null) {
            $head = $this->getPullRequest($id)['source']['commit']['hash'] ?? null;

            if (! is_string($head) || $head === '') {
                throw new RuntimeException("Pull request #{$id} has no head commit to comment on.");
            }

            return (array) $this->request(15)->post($this->url("pulls/{$id}/comments"), array_filter([
                'body' => $content,
                'commit_id' => $head,
                'path' => $path,
                'line' => $line,
                'side' => $line === null ? null : 'RIGHT',
                'subject_type' => $line === null ? 'file' : null,
            ], fn (mixed $value): bool => $value !== null))->throw()->json();
        }

        return (array) $this->request(15)->post($this->url("issues/{$id}/comments"), ['body' => $content])->throw()->json();
    }

    public function updatePullRequestComment(string $id, int $commentId, string $content): array
    {
        $response = $this->request(15)->patch($this->url("pulls/comments/{$commentId}"), ['body' => $content]);

        if ($response->status() === 404) {
            $response = $this->request(15)->patch($this->url("issues/comments/{$commentId}"), ['body' => $content]);
        }

        return (array) $response->throw()->json();
    }

    /**
     * Resolves the review thread a comment on a line is in, or minimises a comment
     * on the pull request as resolved; or the other way around.
     */
    public function resolvePullRequestComment(string $id, int $commentId, bool $resolved = true): array
    {
        $thread = $this->threads($id, fresh: true)['threads'][$commentId] ?? null;

        if ($thread !== null) {
            $mutation = $resolved ? 'resolveReviewThread' : 'unresolveReviewThread';

            return $this->graphql("mutation(\$id: ID!) { {$mutation}(input: {threadId: \$id}) { thread { isResolved } } }", ['id' => $thread['id']]);
        }

        $node = $this->request(10)->get($this->url("issues/comments/{$commentId}"))->throw()->json('node_id');

        if (! is_string($node) || $node === '') {
            throw new RuntimeException("Comment {$commentId} is not on pull request #{$id}.");
        }

        return $resolved
            ? $this->graphql('mutation($id: ID!) { minimizeComment(input: {subjectId: $id, classifier: RESOLVED}) { minimizedComment { isMinimized } } }', ['id' => $node])
            : $this->graphql('mutation($id: ID!) { unminimizeComment(input: {subjectId: $id}) { unminimizedComment { isMinimized } } }', ['id' => $node]);
    }

    public function getPullRequestTasks(string $id): array
    {
        return [];
    }

    public function createPullRequestTask(string $id, string $content, ?int $commentId = null): array
    {
        throw new LogicException('GitHub has no tasks on a pull request.');
    }

    public function resolvePullRequestTask(string $id, int $taskId, string $content): array
    {
        throw new LogicException('GitHub has no tasks on a pull request.');
    }

    public function updatePullRequestDescription(string $id, string $title, string $description): int
    {
        return $this->request(15)->patch($this->url("pulls/{$id}"), ['title' => $title, 'body' => $description])->status();
    }

    /**
     * An approving review, or the dismissal of the token's approval. Approving
     * again while the last one stands adds nothing, so it is left at that rather
     * than adding a review to the pull request's history on every run.
     */
    public function approve(string $id, bool $approved): int
    {
        return $this->verdict($id, 'APPROVED', 'APPROVE', $approved, '');
    }

    /**
     * A review that requests changes, or the dismissal of the token's request.
     */
    public function requestChanges(string $id, bool $requested): int
    {
        return $this->verdict($id, 'CHANGES_REQUESTED', 'REQUEST_CHANGES', $requested, 'The AI review found something that holds the merge: see its comments and the description.');
    }

    /**
     * @return int the HTTP status: 404 for nothing to withdraw, as Bitbucket answers
     */
    protected function verdict(string $id, string $state, string $event, bool $wanted, string $body): int
    {
        $last = $this->lastOwnReview($id);

        if ($wanted) {
            if (($last['state'] ?? null) === $state) {
                return 200;
            }

            return $this->request(15)->post($this->url("pulls/{$id}/reviews"), array_filter(['event' => $event, 'body' => $body]))->status();
        }

        if (($last['state'] ?? null) !== $state || ! isset($last['id'])) {
            return 404;
        }

        return $this->request(15)->put($this->url("pulls/{$id}/reviews/{$last['id']}/dismissals"), [
            'event' => 'DISMISS',
            'message' => 'Withdrawn by the AI review after a newer review of this pull request.',
        ])->status();
    }

    /**
     * The newest review the token's account left that approved, requested changes
     * or was dismissed: what stands of it now. A review that only commented does
     * not change that.
     *
     * @return array<string, mixed>|null
     */
    protected function lastOwnReview(string $id): ?array
    {
        $account = $this->accountId();

        if ($account === null) {
            return null;
        }

        return collect($this->all($this->url("pulls/{$id}/reviews")))
            ->filter(fn (mixed $review): bool => is_array($review)
                && (string) ($review['user']['id'] ?? '') === $account
                && in_array($review['state'] ?? null, ['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'], true))
            ->last();
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

    /**
     * A commit status, which the pull request lists among its checks. Without a
     * token there is nothing to show it with.
     */
    public function setBuildStatus(string $commit, BuildState $state, string $url, string $description): void
    {
        if ($this->token === null) {
            return;
        }

        $this->request(15)->post($this->url("statuses/{$commit}"), [
            'context' => self::STATUS_CONTEXT,
            'state' => match ($state) {
                BuildState::InProgress => 'pending',
                BuildState::Successful => 'success',
                BuildState::Failed => 'failure',
                BuildState::Stopped => 'error',
            },
            'target_url' => $url,
            // GitHub refuses a longer one.
            'description' => mb_substr($description, 0, 140),
        ])->throw();
    }

    /**
     * A report on a commit is a check run, which only a GitHub App may create. The
     * findings are on the pull request as comments, and the status says how it ended.
     */
    public function putCommitReport(string $commit, array $report): void {}

    public function addCommitReportAnnotations(string $commit, array $annotations): ?int
    {
        return null;
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
     * A pull request from a fork has its pull ref as its source branch: the fork's
     * own branch name means another branch in this repository, if any. What the
     * fork calls it is kept as `fork_branch`, owner:branch, for people to read.
     *
     * @param  array<string, mixed>  $pull  as GitHub describes it, from its API or a webhook
     * @return array<string, mixed> as the review reads a pull request
     */
    public static function pullRequest(array $pull): array
    {
        $fork = ($pull['head']['repo']['full_name'] ?? null) !== ($pull['base']['repo']['full_name'] ?? null);
        $branch = $fork ? PullRef::name((int) ($pull['number'] ?? 0)) : (string) ($pull['head']['ref'] ?? '');

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
            'source' => ['branch' => ['name' => $branch], 'commit' => ['hash' => (string) ($pull['head']['sha'] ?? '')]],
            'destination' => ['branch' => ['name' => (string) ($pull['base']['ref'] ?? '')], 'commit' => ['hash' => (string) ($pull['base']['sha'] ?? '')]],
            'merge_commit' => isset($pull['merge_commit_sha']) && isset($pull['merged_at']) ? ['hash' => (string) $pull['merge_commit_sha']] : null,
            'author' => ['display_name' => (string) ($pull['user']['login'] ?? '')],
            'updated_on' => $pull['updated_at'] ?? null,
            // Whether its branch is in this repository: one from a fork is not.
            'from_fork' => $fork,
            'fork_branch' => $fork ? (string) ($pull['head']['label'] ?? $pull['head']['ref'] ?? '') : null,
        ];
    }

    /**
     * The review threads of a pull request, by each comment in them, and which of
     * its comments on the pull request itself are minimised as resolved. Only
     * GitHub's GraphQL API says either; without a token, or when it does not
     * answer, nothing is known to be resolved.
     *
     * A comment is matched by fullDatabaseId, its REST id as a BigInt string:
     * databaseId is a 32-bit Int, which GitHub's comment ids have outgrown.
     *
     * @return array{threads: array<int, array{id: string, resolved: bool}>, minimized: array<int, bool>}
     */
    protected function threads(string $id, bool $fresh = false): array
    {
        if (! $fresh && isset($this->threads[$id])) {
            return $this->threads[$id];
        }

        $known = ['threads' => [], 'minimized' => []];

        if ($this->token === null) {
            return $known;
        }

        try {
            $data = $this->graphql(<<<'GRAPHQL'
                query($owner: String!, $name: String!, $number: Int!) {
                  repository(owner: $owner, name: $name) {
                    pullRequest(number: $number) {
                      reviewThreads(first: 100) { nodes { id isResolved comments(first: 100) { nodes { fullDatabaseId } } } }
                      comments(last: 100) { nodes { fullDatabaseId isMinimized minimizedReason } }
                    }
                  }
                }
                GRAPHQL, ['owner' => $this->owner, 'name' => $this->name, 'number' => (int) $id]);
        } catch (Throwable $exception) {
            report($exception);

            return $known;
        }

        foreach ((array) ($data['repository']['pullRequest']['reviewThreads']['nodes'] ?? []) as $thread) {
            foreach ((array) ($thread['comments']['nodes'] ?? []) as $comment) {
                if (is_numeric($comment['fullDatabaseId'] ?? null) && is_string($thread['id'] ?? null)) {
                    $known['threads'][(int) $comment['fullDatabaseId']] = ['id' => $thread['id'], 'resolved' => (bool) ($thread['isResolved'] ?? false)];
                }
            }
        }

        foreach ((array) ($data['repository']['pullRequest']['comments']['nodes'] ?? []) as $comment) {
            if (is_numeric($comment['fullDatabaseId'] ?? null)) {
                $known['minimized'][(int) $comment['fullDatabaseId']] = ($comment['isMinimized'] ?? false) === true
                    && strtolower((string) ($comment['minimizedReason'] ?? '')) === 'resolved';
            }
        }

        return $this->threads[$id] = $known;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the answer's data
     *
     * @throws RuntimeException when GitHub answers with errors
     */
    protected function graphql(string $query, array $variables): array
    {
        $response = $this->request(15)->post(self::API.'/graphql', ['query' => $query, 'variables' => $variables])->throw();
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw new RuntimeException('GitHub answered: '.implode('; ', array_map(fn (mixed $error): string => (string) ($error['message'] ?? 'an error'), $errors)));
        }

        return (array) $response->json('data');
    }

    /**
     * Every page of a list, up to MAX_PAGES. A failed page throws: a partial list
     * reads as one that ends there.
     *
     * @return array<int, mixed>
     */
    protected function all(string $url): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            /** @var Response $response */
            $response = $this->request(15)->get($url, ['per_page' => 100, 'page' => $page])->throw();
            $batch = array_values((array) $response->json());
            $items = [...$items, ...$batch];

            if (count($batch) < 100) {
                break;
            }
        }

        return $items;
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
