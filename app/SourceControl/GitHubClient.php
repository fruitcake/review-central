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
class GitHubClient implements FiltersSecrets, OpensPullRequests, SourceControl
{
    use SecretFiltering;

    /** Pages of 100 read before giving up on a list. */
    public const MAX_PAGES = 20;

    /** The name a review's commit status goes by; a later one of the same name replaces it. */
    public const STATUS_CONTEXT = 'AI Review';

    /** The button on a check that waits, and what GitHub sends back when it is pressed. */
    public const APPROVE_LABEL = 'Approve and review';

    public const APPROVE_ACTION = 'approve-review';

    /** What an issue comment written as a reply says its parent is. */
    public const PARENT_MARKER = '/\[\/\/\]:\s*#\s*\(ai-reply-to (\d+)\)/';

    /** The line such a reply opens with, for people: the comment it answers, as a link. */
    protected const PARENT_LINK = '/\A↪ \[In reply to this comment\]\([^)\s]*\)\n\n/u';

    protected const API = 'https://api.github.com';

    /** The account the token acts as, once asked. */
    protected ?string $account = null;

    /**
     * Review threads and folded comments of a pull request, once asked: by comment.
     *
     * @var array<string, array{threads: array<int, array{id: string, resolved: bool}>, minimized: array<int, bool>}>
     */
    protected array $threads = [];

    /**
     * @param  string|null  $appAccountId  set when the token is a GitHub App's: the id of its <slug>[bot] account
     */
    public function __construct(
        protected string $owner,
        protected string $name,
        protected ?string $token = null,
        protected ?string $appAccountId = null,
    ) {}

    /**
     * Whether this reaches GitHub as the app: then the review's state is a check
     * run, with its report and its findings in the diff, rather than a commit status.
     */
    public function isApp(): bool
    {
        return $this->token !== null && $this->appAccountId !== null;
    }

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

        // An app's token has no user of its own to ask about.
        if ($this->appAccountId !== null) {
            return $this->appAccountId;
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
     * GitHub works out whether it merges after it is asked, and says null until
     * then; it does not name the files.
     */
    public function mergeConflicts(string $id): ?array
    {
        $response = $this->request(10)->get($this->url("pulls/{$id}"));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        $mergeable = $response->throw()->json('mergeable');

        return is_bool($mergeable) ? ['conflicts' => ! $mergeable, 'files' => []] : null;
    }

    public function openPullRequests(): array
    {
        // Open is what GitHub lists by default.
        return array_map(fn (array $pull): array => self::pullRequest($pull), array_values(array_filter($this->all($this->url('pulls')), 'is_array')));
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

                // Read back as what was written: the link is for people on GitHub.
                if ($parent !== null && ! isset($comment['in_reply_to_id'])) {
                    $body = (string) preg_replace(self::PARENT_LINK, '', $body);
                }
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
            // GitHub shows it on its own, so it links to what it answers.
            return (array) $this->request(15)->post($this->url("issues/{$id}/comments"), [
                'body' => '↪ [In reply to this comment]('.$this->commentLink((int) $id, $parentId).")\n\n".rtrim($content)."\n\n[//]: # (ai-reply-to {$parentId})",
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

    /**
     * Into the repository's default branch unless another is named. GitHub has no
     * default reviewers to add: a CODEOWNERS file asks its own.
     */
    public function openPullRequest(string $branch, ?string $destination, string $title, string $description, bool $draft): array
    {
        $destination ??= (string) ($this->getRepository()['default_branch'] ?? 'main');

        $pull = $this->request(20)->post($this->url('pulls'), [
            'title' => $title,
            'head' => $branch,
            'base' => $destination,
            'body' => $description,
            'draft' => $draft,
        ])->throw()->json();

        return ['id' => (int) ($pull['number'] ?? 0), 'url' => (string) ($pull['html_url'] ?? $this->pullRequestUrl((int) ($pull['number'] ?? 0)))];
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

    public function fileOnMainBranch(string $path): string|false|null
    {
        $response = $this->request(10)->accept('application/vnd.github.raw')
            ->get($this->url('contents/'.implode('/', array_map('rawurlencode', explode('/', $path)))));

        // GitHub answers 404 for a repository it hides as well as for a file that is
        // not there: only one that can be read says the file is gone.
        return match ($response->status()) {
            404 => $this->getRepository() === null ? null : false,
            403 => null,
            default => $response->throw()->body(),
        };
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

        if ($this->isApp()) {
            $this->checkRun($commit, [
                'details_url' => $url,
                'output' => ['title' => mb_substr($description, 0, 255), 'summary' => $description],
            ] + match ($state) {
                BuildState::InProgress => ['status' => 'in_progress'],
                BuildState::Successful => ['status' => 'completed', 'conclusion' => 'success'],
                BuildState::Failed => ['status' => 'completed', 'conclusion' => 'failure'],
                BuildState::Stopped => ['status' => 'completed', 'conclusion' => 'cancelled'],
            }, keepReport: $state !== BuildState::InProgress);

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
     * A report on a commit is a check run, which only a GitHub App may create. With
     * a token the findings are on the pull request as comments, and the status says
     * how it ended; as the app the report is the check's summary, beside its state.
     */
    public function putCommitReport(string $commit, array $report): void
    {
        if (! $this->isApp()) {
            return;
        }

        $data = collect(is_array($report['data'] ?? null) ? $report['data'] : [])
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['title']))
            ->map(fn (array $row): string => '| '.$row['title'].' | '.($row['value'] ?? '').' |')
            ->implode("\n");

        $this->checkRun($commit, ['output' => [
            'title' => mb_substr((string) ($report['title'] ?? 'AI Review'), 0, 255),
            'summary' => mb_substr((string) ($report['details'] ?? ''), 0, 65000) ?: 'The review wrote no summary.',
            'text' => $data === '' ? '' : "| | |\n|---|---|\n{$data}",
        ]]);
    }

    /**
     * The findings in the diff, as the check run's annotations: GitHub takes fifty a
     * call, and keeps adding them to what the check already has.
     */
    public function addCommitReportAnnotations(string $commit, array $annotations): ?int
    {
        if (! $this->isApp()) {
            return null;
        }

        $existing = $this->findCheckRun($commit);
        $output = [
            'title' => (string) ($existing['output']['title'] ?? 'AI Review'),
            'summary' => (string) ($existing['output']['summary'] ?? '') ?: 'The review\'s findings.',
        ];

        foreach (array_chunk(array_map(self::checkAnnotation(...), $annotations), 50) as $batch) {
            $this->checkRun($commit, ['output' => $output + ['annotations' => $batch]]);
        }

        return null;
    }

    /**
     * Shows a running review's progress in its check: only while the check runs,
     * so a finished one keeps its report.
     *
     * @param  list<string>  $lines  the end of the review's log
     */
    public function showProgress(string $commit, string $title, string $summary, array $lines): void
    {
        if (! $this->isApp()) {
            return;
        }

        $existing = $this->findCheckRun($commit);

        if (($existing['status'] ?? null) !== 'in_progress' || ! isset($existing['id'])) {
            return;
        }

        // A fence no line of a log is likely to close early.
        $text = $lines === [] ? '' : "~~~~text\n".mb_substr(implode("\n", $lines), -60000)."\n~~~~";

        $this->request(10)->patch($this->url("check-runs/{$existing['id']}"), [
            'output' => ['title' => mb_substr($title, 0, 255), 'summary' => $summary, 'text' => $text],
        ])->throw();
    }

    /**
     * Asks for someone in the team to start the review, on the pull request's own
     * page: as the app, a check that needs action, with a button that starts it.
     * Otherwise a commit status that waits, leading to where it is started.
     */
    public function requestApproval(string $commit, string $url, string $description): void
    {
        if (! $this->isApp()) {
            $this->setBuildStatus($commit, BuildState::InProgress, $url, $description);

            return;
        }

        $this->checkRun($commit, [
            'status' => 'completed',
            'conclusion' => 'action_required',
            'details_url' => $url,
            'output' => [
                'title' => 'Waiting for approval',
                'summary' => $description.'. A trusted account of the team can start it with "'.self::APPROVE_LABEL.'" above, or in Review Central.',
            ],
            'actions' => [[
                'label' => self::APPROVE_LABEL,
                'description' => 'Review this pull request with AI',
                'identifier' => self::APPROVE_ACTION,
            ]],
        ]);
    }

    /**
     * A Bitbucket annotation as a check run's.
     *
     * @param  array<string, mixed>  $annotation
     * @return array<string, mixed>
     */
    protected static function checkAnnotation(array $annotation): array
    {
        $line = is_int($annotation['line'] ?? null) && $annotation['line'] > 0 ? $annotation['line'] : 1;

        return [
            'path' => (string) ($annotation['path'] ?? ''),
            'start_line' => $line,
            'end_line' => $line,
            'annotation_level' => match ($annotation['severity'] ?? null) {
                'HIGH', 'CRITICAL' => 'failure',
                'MEDIUM' => 'warning',
                default => 'notice',
            },
            'title' => mb_substr((string) ($annotation['title'] ?? ''), 0, 255),
            'message' => mb_substr((string) ($annotation['details'] ?? $annotation['summary'] ?? ''), 0, 64000),
        ];
    }

    /**
     * The app's check run on a commit, by its name: the newest, when there is one.
     *
     * @return array<string, mixed>|null
     */
    protected function findCheckRun(string $commit): ?array
    {
        $runs = $this->request(10)->get($this->url("commits/{$commit}/check-runs"), ['check_name' => self::STATUS_CONTEXT, 'filter' => 'latest', 'per_page' => 1])
            ->throw()->json('check_runs');

        return is_array($runs) && is_array($runs[0] ?? null) ? $runs[0] : null;
    }

    /**
     * Changes the commit's check run, or starts a new one: one per commit for each
     * time the review is asked for, so the pull request shows one check. A state
     * that ends a check keeps the report already in it.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function checkRun(string $commit, array $fields, bool $keepReport = false): void
    {
        $existing = $this->findCheckRun($commit);
        $finished = ($existing['status'] ?? null) === 'completed';

        // A finished check is not set going again: a new state starts a new one,
        // which GitHub then shows in its place.
        if (is_array($existing) && isset($existing['id']) && ! ($finished && isset($fields['status']))) {
            if ($keepReport && filled($existing['output']['summary'] ?? null)) {
                $fields['output'] = ['title' => $fields['output']['title'] ?? (string) ($existing['output']['title'] ?? ''), 'summary' => (string) $existing['output']['summary']]
                    + (filled($existing['output']['text'] ?? null) ? ['text' => (string) $existing['output']['text']] : []);
            }

            // Its button goes once the review runs or ends.
            if (isset($fields['status']) && ($fields['conclusion'] ?? null) !== 'action_required') {
                $fields += ['actions' => []];
            }

            $this->request(15)->patch($this->url("check-runs/{$existing['id']}"), $fields)->throw();

            return;
        }

        // Only a report, with no check to put it on: it stands as a finished one.
        $fields += isset($fields['status']) ? [] : ['status' => 'completed', 'conclusion' => 'neutral'];

        $this->request(15)->post($this->url('check-runs'), ['name' => self::STATUS_CONTEXT, 'head_sha' => $commit] + $fields)->throw();
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

    /**
     * The address of a comment on the pull request: one on a line, as a reply in a
     * thread that takes no reply of its own, or one on the pull request itself.
     */
    protected function commentLink(int $pullRequestId, int $commentId): string
    {
        foreach (["pulls/comments/{$commentId}", "issues/comments/{$commentId}"] as $path) {
            $url = rescue(fn (): mixed => $this->request(10)->get($this->url($path))->json('html_url'), null, false);

            if (is_string($url) && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return $this->pullRequestUrl($pullRequestId)."#issuecomment-{$commentId}";
    }

    public function repositoryUrl(): string
    {
        return "https://github.com/{$this->owner}/{$this->name}";
    }

    public function fileUrl(string $commit, string $path, ?int $line = null): ?string
    {
        $path = implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));

        return "https://github.com/{$this->owner}/{$this->name}/blob/{$commit}/{$path}".($line === null ? '' : "#L{$line}");
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
            // As Bitbucket names them: the ID and the name signed in with.
            'author' => [
                'display_name' => (string) ($pull['user']['login'] ?? ''),
                'account_id' => is_numeric($pull['user']['id'] ?? null) ? (string) $pull['user']['id'] : null,
                'nickname' => is_string($pull['user']['login'] ?? null) ? $pull['user']['login'] : null,
            ],
            'created_on' => $pull['created_at'] ?? null,
            'updated_on' => $pull['updated_at'] ?? null,
            // Who is asked to review and has yet to: GitHub lists only them, and
            // those who reviewed are told of by their reviews.
            'reviewers' => array_values(array_map(fn (array $user): array => self::user($user), array_filter((array) ($pull['requested_reviewers'] ?? []), 'is_array'))),
            'reviewers_partial' => true,
            // Whether its branch is in this repository: one from a fork is not.
            'from_fork' => $fork,
            'fork_branch' => $fork ? (string) ($pull['head']['label'] ?? $pull['head']['ref'] ?? '') : null,
        ];
    }

    /**
     * A pull request with what its reviews say as Bitbucket's participants: each
     * reviewer's newest approval or request for changes, oldest review first. A
     * comment alone says neither; a dismissed review asks for a new one.
     *
     * @param  array<string, mixed>  $pullRequest  as pullRequest() gives it
     * @param  array<int, array<string, mixed>>  $reviews  as GitHub describes them
     * @return array<string, mixed>
     */
    public static function withReviews(array $pullRequest, array $reviews): array
    {
        $participants = [];

        foreach ($reviews as $review) {
            // Upper case from the REST API, lower case in a webhook.
            $state = match (strtoupper((string) ($review['state'] ?? ''))) {
                'APPROVED' => Reviewers::APPROVED,
                'CHANGES_REQUESTED' => Reviewers::CHANGES_REQUESTED,
                'DISMISSED' => null,
                default => false,
            };

            if ($state === false || ! is_array($review['user'] ?? null)) {
                continue;
            }

            $user = self::user($review['user']);
            $participants[Reviewers::key(Reviewers::person($user))] = [
                'user' => $user,
                'role' => 'REVIEWER',
                'approved' => $state === Reviewers::APPROVED,
                'state' => $state,
                'participated_on' => $review['submitted_at'] ?? null,
            ];
        }

        return ['participants' => array_values($participants)] + $pullRequest;
    }

    /**
     * Every review of a pull request, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPullRequestReviews(string $id): array
    {
        // Every page: each inline comment the review posts is a review of its own,
        // and the newest, which say where a reviewer stands, come last.
        return array_values(array_filter($this->all($this->url("pulls/{$id}/reviews")), 'is_array'));
    }

    /**
     * A GitHub user as Bitbucket names one: the ID and the name signed in with.
     *
     * @param  array<string, mixed>  $user
     * @return array{display_name: string, account_id: string|null, nickname: string|null}
     */
    protected static function user(array $user): array
    {
        return [
            'display_name' => (string) ($user['login'] ?? ''),
            'account_id' => is_numeric($user['id'] ?? null) ? (string) $user['id'] : null,
            'nickname' => is_string($user['login'] ?? null) ? $user['login'] : null,
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

        $request = $this->filtered($request);

        return $this->token === null ? $request : $request->withToken($this->token);
    }
}
