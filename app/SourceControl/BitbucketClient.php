<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Enums\BuildState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A repository on Bitbucket Cloud.
 */
class BitbucketClient implements FiltersSecrets, SourceControl
{
    use SecretFiltering;

    /**
     * Pages of 100 comments or tasks read before giving up on a pull request.
     */
    public const MAX_PAGES = 20;

    /**
     * One status per commit: a later one of the same key replaces it.
     */
    public const BUILD_STATUS_KEY = 'ai-review';

    /**
     * One report per commit, under the key the report step uses.
     */
    public const REPORT_KEY = 'ai-review';

    protected const API = 'https://api.bitbucket.org/2.0';

    /**
     * @param  string|null  $username  with an API token or app password; without it the token is sent as a bearer token
     */
    public function __construct(
        protected string $workspace,
        protected string $slug,
        protected string $token,
        protected ?string $username = null,
    ) {}

    public function getPullRequest(string $id): ?array
    {
        $response = $this->request(10)->get($this->url("pullrequests/{$id}"));

        if ($response->status() === 404) {
            return null;
        }

        // An expired token or a repository this token cannot see is answered with a
        // JSON error envelope, which would read as a pull request with every field null.
        $pullRequest = $response->throw()->json();

        // Whether its branch is in this repository: one from a fork is not, and
        // Bitbucket keeps no ref of it here to fetch instead.
        $source = $pullRequest['source']['repository']['full_name'] ?? null;
        $destination = $pullRequest['destination']['repository']['full_name'] ?? null;

        return is_array($pullRequest) ? $pullRequest + ['from_fork' => is_string($source) && is_string($destination) && strcasecmp($source, $destination) !== 0] : $pullRequest;
    }

    public function findOpenPullRequestByBranch(string $branch): ?array
    {
        $response = $this->request(10)->get($this->url('pullrequests'), [
            'state' => 'OPEN',
            'q' => 'source.branch.name="'.addcslashes($branch, '"\\').'"',
            'pagelen' => 50,
        ])->throw();

        // Newest first, so a branch with more than one open pull request resolves to
        // the one someone is working on.
        /** @var array<int, array<string, mixed>> $pullRequests */
        $pullRequests = $response->json('values') ?? [];

        usort($pullRequests, fn (array $a, array $b): int => strcmp((string) ($b['updated_on'] ?? ''), (string) ($a['updated_on'] ?? '')));

        return $pullRequests[0] ?? null;
    }

    /**
     * The files that conflict, which the pull request's address leads to: none
     * when it merges cleanly.
     */
    public function mergeConflicts(string $id): ?array
    {
        $response = $this->request(15)->get($this->url("pullrequests/{$id}/conflicts"));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        $conflicts = array_values(array_filter((array) ($response->throw()->json('values') ?? []), 'is_array'));
        $files = array_values(array_filter(array_map(fn (array $conflict): ?string => is_string($conflict['path'] ?? null) ? $conflict['path'] : null, $conflicts), fn (?string $path): bool => $path !== null));

        return ['conflicts' => $conflicts !== [], 'files' => $files];
    }

    public function openPullRequests(): array
    {
        return array_values(array_filter($this->pages($this->url('pullrequests').'?state=OPEN&pagelen=50'), 'is_array'));
    }

    public function publishes(): bool
    {
        return true;
    }

    public function supportsTasks(): bool
    {
        return true;
    }

    public function accountId(): ?string
    {
        // Not every kind of token may ask who it is; then nobody is mentioned by it.
        $id = rescue(fn (): mixed => $this->request(8)->get(self::API.'/user', ['fields' => 'account_id'])->json('account_id'), null, false);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function getPullRequestComments(string $id): array
    {
        return $this->pages($this->url("pullrequests/{$id}/comments").'?pagelen=100&sort=created_on', retry: true);
    }

    public function addPullRequestComment(string $id, string $content, ?string $path = null, ?int $line = null, ?int $parentId = null): array
    {
        $payload = ['content' => ['raw' => $content]];

        if ($path !== null && $path !== '') {
            // `to` is the line in the new version of the file. Left out for a
            // file-level comment, which Bitbucket anchors to the file.
            $payload['inline'] = $line === null ? ['path' => $path] : ['path' => $path, 'to' => $line];
        } elseif ($parentId !== null) {
            $payload['parent'] = ['id' => $parentId];
        }

        return $this->request(15)->post($this->url("pullrequests/{$id}/comments"), $payload)->throw()->json() ?? [];
    }

    public function updatePullRequestComment(string $id, int $commentId, string $content): array
    {
        return $this->request(15)
            ->put($this->url("pullrequests/{$id}/comments/{$commentId}"), ['content' => ['raw' => $content]])
            ->throw()
            ->json() ?? [];
    }

    public function resolvePullRequestComment(string $id, int $commentId, bool $resolved = true): array
    {
        $url = $this->url("pullrequests/{$id}/comments/{$commentId}/resolve");
        $response = $resolved ? $this->request(15)->post($url) : $this->request(15)->delete($url);

        return $response->throw()->json() ?? [];
    }

    public function getPullRequestTasks(string $id): array
    {
        return $this->pages($this->url("pullrequests/{$id}/tasks").'?pagelen=100');
    }

    public function createPullRequestTask(string $id, string $content, ?int $commentId = null): array
    {
        $payload = ['content' => ['raw' => $content]];

        if ($commentId !== null) {
            $payload['comment'] = ['id' => $commentId];
        }

        return $this->request(15)->post($this->url("pullrequests/{$id}/tasks"), $payload)->throw()->json() ?? [];
    }

    public function resolvePullRequestTask(string $id, int $taskId, string $content): array
    {
        return $this->request(15)
            ->put($this->url("pullrequests/{$id}/tasks/{$taskId}"), ['state' => 'RESOLVED', 'content' => ['raw' => $content]])
            ->throw()
            ->json() ?? [];
    }

    public function updatePullRequestDescription(string $id, string $title, string $description): int
    {
        return $this->request(20)->put($this->url("pullrequests/{$id}"), ['title' => $title, 'description' => $description])->status();
    }

    public function approve(string $id, bool $approved): int
    {
        $url = $this->url("pullrequests/{$id}/approve");

        return ($approved ? $this->request(15)->post($url) : $this->request(15)->delete($url))->status();
    }

    public function requestChanges(string $id, bool $requested): int
    {
        $url = $this->url("pullrequests/{$id}/request-changes");

        return ($requested ? $this->request(15)->post($url) : $this->request(15)->delete($url))->status();
    }

    public function getCommit(string $commit): ?array
    {
        try {
            $response = $this->request(8)->get($this->url("commit/{$commit}"));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // The subject only: a commit body can be paragraphs, and this goes inline in
        // a sentence.
        $message = trim(strtok((string) $response->json('message'), "\n") ?: '');

        // raw is "Name <email>"; the address is not ours to repeat in a comment.
        $author = trim((string) ($response->json('author.user.display_name') ?? ''));

        if ($author === '') {
            $author = trim((string) preg_replace('/\s*<[^>]*>\s*/', '', (string) $response->json('author.raw')));
        }

        return $message === '' && $author === '' ? null : ['message' => $message, 'author' => $author];
    }

    public function resolveCommit(string $commit): ?string
    {
        $hash = rescue(fn (): mixed => $this->request(8)->get($this->url("commit/{$commit}"), ['fields' => 'hash'])->json('hash'), null, false);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function getPullRequestDiff(string $id): string
    {
        // Bitbucket answers with a redirect to the diff of the two commits.
        return $this->request(60)->accept('text/plain')->get($this->url("pullrequests/{$id}/diff"))->throw()->body();
    }

    public function setBuildStatus(string $commit, BuildState $state, string $url, string $description): void
    {
        $this->request(15)->post($this->url("commit/{$commit}/statuses/build"), [
            'key' => self::BUILD_STATUS_KEY,
            'name' => 'AI Review',
            'state' => match ($state) {
                BuildState::InProgress => 'INPROGRESS',
                BuildState::Successful => 'SUCCESSFUL',
                BuildState::Failed => 'FAILED',
                BuildState::Stopped => 'STOPPED',
            },
            'url' => $url,
            'description' => mb_substr($description, 0, 255),
        ])->throw();
    }

    public function putCommitReport(string $commit, array $report): void
    {
        $this->request(15)->put($this->url("commit/{$commit}/reports/".self::REPORT_KEY), $this->within($report, ['title' => 450, 'details' => 2000, 'reporter' => 450]))->throw();
    }

    public function addCommitReportAnnotations(string $commit, array $annotations): ?int
    {
        $url = $this->url("commit/{$commit}/reports/".self::REPORT_KEY.'/annotations');

        $this->request(30)->post($url, array_map(fn (array $annotation): array => $this->within($annotation, ['title' => 450, 'summary' => 450, 'details' => 2000]), $annotations))->throw();

        // Bitbucket answers 200 whether or not it kept anything, so it is asked.
        $size = rescue(fn (): mixed => $this->request(10)->get($url, ['pagelen' => 1])->json('size'), null, false);

        return is_int($size) ? $size : null;
    }

    /**
     * Cuts the texts of a report or an annotation to what Code Insights takes. One
     * character too many and Bitbucket refuses the whole of it, and a review's
     * summary is easily longer than a report's details may be. The full text is on
     * the pull request.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, int>  $limits
     * @return array<string, mixed>
     */
    protected function within(array $fields, array $limits): array
    {
        foreach ($limits as $field => $limit) {
            if (is_string($fields[$field] ?? null) && mb_strlen($fields[$field]) > $limit) {
                $fields[$field] = rtrim(mb_substr($fields[$field], 0, $limit - 1)).'…';
            }
        }

        return $fields;
    }

    public function cloneAccess(): array
    {
        return [
            'url' => "https://bitbucket.org/{$this->workspace}/{$this->slug}.git",
            // An access token clones as x-token-auth; an account's API token as
            // the name Bitbucket reserves for those.
            'username' => $this->username === null ? 'x-token-auth' : 'x-bitbucket-api-token-auth',
            'password' => $this->token,
        ];
    }

    public function commitUrl(string $commit): string
    {
        return "https://bitbucket.org/{$this->workspace}/{$this->slug}/commits/{$commit}";
    }

    public function repositoryUrl(): string
    {
        return "https://bitbucket.org/{$this->workspace}/{$this->slug}";
    }

    public function fileUrl(string $commit, string $path, ?int $line = null): ?string
    {
        return "https://bitbucket.org/{$this->workspace}/{$this->slug}/src/{$commit}/".self::encodePath($path).($line === null ? '' : "#lines-{$line}");
    }

    /**
     * A path as it goes in an address: each part encoded, the slashes kept.
     */
    protected static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));
    }

    public function pullRequestUrl(int $pullRequestId): string
    {
        return "https://bitbucket.org/{$this->workspace}/{$this->slug}/pull-requests/{$pullRequestId}";
    }

    public function fileOnMainBranch(string $path): string|false|null
    {
        $branch = $this->getRepository()['mainbranch']['name'] ?? null;

        if (! is_string($branch) || $branch === '') {
            return null;
        }

        $response = $this->request(10)->get($this->url('src/'.rawurlencode($branch).'/'.implode('/', array_map('rawurlencode', explode('/', $path)))));

        // Not found in a repository that could be read just now: the file is gone.
        return match ($response->status()) {
            404 => false,
            403 => null,
            default => $response->throw()->body(),
        };
    }

    public function getFile(string $path): ?string
    {
        $branch = $this->getRepository()['mainbranch']['name'] ?? null;

        if (! is_string($branch) || $branch === '') {
            return null;
        }

        $response = $this->request(10)->get($this->url('src/'.rawurlencode($branch).'/'.implode('/', array_map('rawurlencode', explode('/', $path)))));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        return $response->throw()->body();
    }

    /**
     * @return array<string, mixed>|null null when the token cannot see the repository
     */
    public function getRepository(): ?array
    {
        $response = $this->request(10)->get(rtrim($this->url(''), '/'));

        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * Every value of a paginated listing, up to MAX_PAGES. A page that fails
     * throws rather than return what came before it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pages(string $url, bool $retry = false): array
    {
        $values = [];

        for ($page = 0; $url !== '' && $page < self::MAX_PAGES; $page++) {
            $request = $this->request(10);

            if ($retry) {
                $request->retry(2, 200, throw: false);
            }

            $response = $request->get($url)->throw();

            $values = array_merge($values, $response->json('values') ?? []);
            $url = (string) $response->json('next');
        }

        return $values;
    }

    protected function url(string $path): string
    {
        return self::API."/repositories/{$this->workspace}/{$this->slug}/{$path}";
    }

    protected function request(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout)->connectTimeout(4)->acceptJson()
            // Bitbucket drops a connection now and then ("Connection reset by
            // peer"). Asked again, but not a POST: one that was received before
            // the line went would post its comment twice. Nor a time-out (cURL
            // error 28): a Bitbucket that is slow stays slow, and the report
            // waiting on it holds the review's lock.
            ->retry(3, 500, fn (Throwable $exception, PendingRequest $request, ?string $method): bool => $exception instanceof ConnectionException
                && $method !== 'POST'
                && ! str_contains($exception->getMessage(), 'cURL error 28'), throw: false);

        $request = $this->filtered($request);

        return $this->username !== null
            ? $request->withBasicAuth($this->username, $this->token)
            : $request->withToken($this->token);
    }
}
