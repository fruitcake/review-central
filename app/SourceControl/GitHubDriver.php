<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\SourceControlDriver;
use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use App\Models\Repository;
use App\Support\SetupGuide;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * GitHub, with a personal access token: the review is posted on its pull
 * requests, and its webhook starts one.
 *
 * Without a token and without an owner the connection is for any public
 * repository, which it only reads: all a trial of a public pull request needs.
 */
class GitHubDriver implements ExplainsSetup, SourceControlDriver
{
    /**
     * What the webhook has to deliver: every change to a pull request, and the
     * comments that may ask for a review or ask the reviewer something.
     *
     * @var array<int, string>
     */
    public const WEBHOOK_EVENTS = ['pull_request', 'issue_comment', 'pull_request_review_comment', 'pull_request_review'];

    public function label(): string
    {
        return 'GitHub';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('access_token')->label(__('Personal access token'))
                ->required(false)
                ->helperText(__('A fine-grained token for the repositories, with read access to Contents and Metadata, and read and write access to Pull requests, Issues and Commit statuses; Webhooks too, to have the webhook added for you. The review comments, approves and requests changes as the account that owns it, so give it an account of its own. Leave it empty for public repositories, which are then only read.')),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('workspace')->label(__('Owner'))->rule('regex:/^[\w.-]+$/')
                ->helperText(__('The user or organisation from the address: github.com/<owner>. Leave it empty, with no token, for any public repository.')),
            ReadOnlyClient::field(),
        ];
    }

    /**
     * The permissions are what GitHubClient calls: the pull request, its diff and
     * its files, a comment on its conversation (an issue to GitHub), a status on
     * the commit, and the webhook where it is added for you.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: __('A GitHub token for reviews'),
            url: 'https://github.com/settings/personal-access-tokens/new',
            linkLabel: __('New fine-grained personal access token on GitHub'),
            steps: [
                __('Sign in at GitHub as the account the review acts as: an account of its own, a machine user such as `acme-review`, invited to the organisation with **Write** access to the repositories. GitHub does not let an account approve its own pull request.'),
                __('Open the link, name the token and give it an expiry date. As **Resource owner**, pick the organisation (or the user) whose repositories are reviewed.'),
                __('Under **Repository access**, pick **Only select repositories** with the ones to review, or **All repositories**.'),
                __('Under **Repository permissions**, set the permissions below. For a webhook on the whole organisation, also set **Webhooks: Read and write** under **Organization permissions**.'),
                __('Generate the token, copy it and paste it here. Under Settings, fill in the organisation or user as **Owner**.'),
                __('If the organisation approves fine-grained tokens, an owner approves it under the organisation\'s **Settings → Personal access tokens → Pending requests**.'),
            ],
            permissions: [
                'Contents: Read' => __('clones the repository and reads its commits and files.'),
                'Metadata: Read' => __('GitHub adds it to every token; finds the repositories by name.'),
                'Pull requests: Read and write' => __('reads the pull request and its diff, writes its description, comments on its lines, and approves or requests changes.'),
                'Issues: Read and write' => __('comments on the pull request\'s conversation, which GitHub keeps as an issue.'),
                'Commit statuses: Read and write' => __('sets the "AI Review" status on the commit.'),
                'Webhooks: Read and write' => __('only to have "Add it for me" add the webhook: on each repository, or as an organisation permission on the organisation.'),
            ],
            notes: [
                __('Leave the token and the owner empty for public repositories: they are then only read, and their pull requests can be tried.'),
                __('A token expires: note the date and replace it here before then. **Test** in the list says whether it still works.'),
            ],
        );
    }

    public function test(Connection $connection): void
    {
        $owner = $connection->setting('workspace');
        $response = $this->request($connection)->get($owner === null ? 'https://api.github.com/rate_limit' : 'https://api.github.com/users/'.rawurlencode($owner));

        if (! $response->successful()) {
            throw new ConnectionFailed("GitHub answered {$response->status()}: check the token and the owner.");
        }
    }

    public function searchRepositories(Connection $connection, string $search): array
    {
        $owner = $connection->setting('workspace');

        if ($owner === null) {
            return [];
        }

        $term = trim((string) preg_replace('/[^\w.-]+/', ' ', $search));
        $response = $this->request($connection)->get('https://api.github.com/search/repositories', [
            'q' => trim("{$term} user:{$owner} fork:true"),
            'sort' => 'updated',
            'per_page' => 25,
        ])->throw();

        $found = [];

        foreach ((array) $response->json('items') as $repository) {
            if (is_array($repository) && is_string($repository['name'] ?? null)) {
                $found[$repository['name']] = $repository['name'];
            }
        }

        return $found;
    }

    /**
     * On the organisation, which covers every repository it has and will have,
     * when the token may manage its webhooks. Otherwise, and for a user's own
     * account, on each repository of the connection: one added later needs it
     * added again. A hook that already delivers to this address is updated, so
     * asking twice leaves one.
     */
    public function installWebhook(Connection $connection, string $url): void
    {
        $owner = (string) $connection->setting('workspace');

        if ($owner === '' || $this->token($connection) === null) {
            throw new ConnectionFailed('A webhook needs an owner and a token.');
        }

        $organisation = $this->request($connection)->get('https://api.github.com/users/'.rawurlencode($owner))->json('type') === 'Organization';

        if ($organisation && $this->putHook($connection, 'https://api.github.com/orgs/'.rawurlencode($owner).'/hooks', $url, quietly: true)) {
            return;
        }

        $repositories = Repository::query()->where('connection_id', $connection->getKey())->pluck('slug');

        if ($repositories->isEmpty()) {
            throw new ConnectionFailed($organisation
                ? 'The token may not manage the organisation\'s webhooks, and there is no repository yet to add one to: add a repository first, or give the token the organisation\'s Webhooks permission.'
                : 'Add a repository first: on a user\'s account the webhook goes on each repository.');
        }

        foreach ($repositories as $slug) {
            $this->putHook($connection, 'https://api.github.com/repos/'.rawurlencode($owner).'/'.rawurlencode((string) $slug).'/hooks', $url);
        }
    }

    /**
     * @return bool whether the hook is in place; false only when $quietly and GitHub refused
     */
    protected function putHook(Connection $connection, string $hooks, string $url, bool $quietly = false): bool
    {
        $list = $this->request($connection)->get($hooks, ['per_page' => 100]);

        if ($quietly && ! $list->successful()) {
            return false;
        }

        $existing = collect((array) $list->throw()->json())
            ->first(fn (mixed $hook): bool => is_array($hook) && ($hook['config']['url'] ?? null) === $url);

        $payload = [
            'active' => true,
            'events' => self::WEBHOOK_EVENTS,
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => (string) $connection->webhook_secret, 'insecure_ssl' => '0'],
        ];

        $response = is_array($existing) && isset($existing['id'])
            ? $this->request($connection)->patch("{$hooks}/{$existing['id']}", $payload)
            : $this->request($connection)->post($hooks, ['name' => 'web'] + $payload);

        if ($quietly && ! $response->successful()) {
            return false;
        }

        $response->throw();

        return true;
    }

    public function client(Repository $repository): GitHubClient
    {
        return new GitHubClient($repository->workspace, $repository->slug, $this->token($repository->integration));
    }

    /**
     * What the connection reaches GitHub with: its token, or none for public
     * repositories.
     */
    protected function token(Connection $connection): ?string
    {
        return $connection->credential('access_token');
    }

    /**
     * A delivery signed with the connection's secret, as the review reads it: a
     * pull request that changed, or a comment on one. A comment on the pull
     * request as a whole arrives as one on an issue, without the pull request's
     * branch and state, which are read from GitHub.
     */
    public function webhookEvent(Connection $connection, Request $request): ?WebhookEvent
    {
        $secret = (string) $connection->webhook_secret;
        $signature = $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! is_string($signature)
            || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return null;
        }

        return $this->eventFrom($connection, $request);
    }

    /**
     * A delivery whose signature is already checked, as the review reads it.
     */
    public function eventFrom(Connection $connection, Request $request): WebhookEvent
    {
        $payload = $request->json()->all();
        $event = (string) $request->header('X-GitHub-Event');
        $owner = (string) ($payload['repository']['owner']['login'] ?? '');
        $name = (string) ($payload['repository']['name'] ?? '');

        $comment = match (true) {
            $event === 'issue_comment' && ($payload['action'] ?? null) === 'created' && isset($payload['issue']['pull_request']) => $payload['comment'] ?? null,
            $event === 'pull_request_review_comment' && ($payload['action'] ?? null) === 'created' => $payload['comment'] ?? null,
            default => null,
        };

        if ($event === 'pull_request' && is_array($payload['pull_request'] ?? null)) {
            return new WebhookEvent(WebhookEvent::PULL_REQUEST_CHANGED, $owner, $name, GitHubClient::pullRequest($payload['pull_request']));
        }

        // An approval, a request for changes, or one dismissed: whose turn it is.
        if ($event === 'pull_request_review' && is_array($payload['pull_request'] ?? null) && is_array($payload['review'] ?? null)
            && in_array($payload['action'] ?? null, ['submitted', 'dismissed'], true)) {
            return new WebhookEvent(WebhookEvent::PULL_REQUEST_REVIEWED, $owner, $name, GitHubClient::withReviews(
                GitHubClient::pullRequest($payload['pull_request']),
                [($payload['action'] === 'dismissed' ? ['state' => 'DISMISSED'] : []) + $payload['review']],
            ));
        }

        if (! is_array($comment)) {
            // Anything else, a ping included, is acknowledged and starts nothing.
            return new WebhookEvent($event, $owner, $name);
        }

        $pullRequest = is_array($payload['pull_request'] ?? null)
            ? GitHubClient::pullRequest($payload['pull_request'])
            : $this->readPullRequest($connection, $owner, $name, (int) ($payload['issue']['number'] ?? 0));

        $body = (string) ($comment['body'] ?? '');
        $parent = $comment['in_reply_to_id'] ?? (preg_match(GitHubClient::PARENT_MARKER, $body, $match) === 1 ? $match[1] : null);

        return new WebhookEvent(
            WebhookEvent::COMMENT_CREATED,
            $owner,
            $name,
            $pullRequest,
            $body,
            is_numeric($comment['id'] ?? null) ? (int) $comment['id'] : null,
            is_string($comment['user']['login'] ?? null) ? $comment['user']['login'] : null,
            is_numeric($parent) ? (int) $parent : null,
            is_numeric($comment['user']['id'] ?? null) ? (string) $comment['user']['id'] : null,
            is_string($comment['user']['login'] ?? null) ? $comment['user']['login'] : null,
        );
    }

    /**
     * The pull request a comment on its conversation is about, or only its number
     * when GitHub does not say more: then it reads as not open, and starts nothing.
     *
     * @return array<string, mixed>
     */
    protected function readPullRequest(Connection $connection, string $owner, string $name, int $number): array
    {
        $client = new GitHubClient($owner, $name, $this->token($connection));

        return rescue(fn (): ?array => $client->getPullRequest((string) $number), null) ?? ['id' => $number];
    }

    public function webhookHelp(): string
    {
        return __('Starts a review on every new commit of a pull request, when a draft is marked ready, and on a /review comment. "Add it for me" adds it to the organisation, or to each repository of the connection on a user\'s account. By hand it goes under the repository\'s or organisation\'s Settings, Webhooks: this URL as the payload URL, content type application/json, this secret, and the events Pull requests, Issue comments and Pull request review comments.');
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = Http::timeout(15)->connectTimeout(4)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('review-central');
        $token = $this->token($connection);

        return $token === null ? $request : $request->withToken($token);
    }
}
