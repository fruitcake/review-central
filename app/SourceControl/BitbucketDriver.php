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
 * Bitbucket Cloud.
 */
class BitbucketDriver implements ExplainsSetup, SourceControlDriver
{
    /**
     * What the webhook has to deliver: a comment that may ask for a review, and
     * every change to a pull request.
     *
     * @var array<int, string>
     */
    public const WEBHOOK_EVENTS = [
        'pullrequest:created',
        'pullrequest:updated',
        'pullrequest:fulfilled',
        'pullrequest:rejected',
        'pullrequest:comment_created',
    ];

    public function label(): string
    {
        return 'Bitbucket Cloud';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('access_token')->label('Access token')
                ->helperText('A workspace access token with the scope pullrequest:write, and webhook to have the webhook added for you. The review comments and approves as this token.'),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('workspace')->label('Workspace')->required()->rule('regex:/^[\w.-]+$/')
                ->helperText('The workspace ID from the URL: bitbucket.org/<workspace>.'),
            TextInput::make('username')->label('Account email')->email()
                ->helperText('Only with an API token of a user account, which is sent with this address. Leave empty for a workspace access token.'),
        ];
    }

    /**
     * The scopes are what BitbucketClient calls: the pull requests with their
     * comments, tasks and approval, the commit's status and report, and the
     * workspace's webhooks where they are added for you.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: 'A Bitbucket workspace access token',
            url: 'https://bitbucket.org/account/workspaces/',
            linkLabel: 'Your Bitbucket workspaces',
            steps: [
                'Open the link and pick the workspace, then **Settings** (the cog) → **Security** → **Access tokens** → **Create workspace access token**.',
                'Name it after what it is, such as "AI Review": the review comments and approves under that name.',
                'Tick the scopes below and create it.',
                'Copy the token, which Bitbucket shows once, and paste it here.',
                'Under Settings, fill in the workspace ID from the address `bitbucket.org/<workspace>`, and leave **Account email** empty.',
            ],
            permissions: [
                'pullrequest:write' => 'reads the pull requests and their diff, comments, adds and resolves tasks, approves or requests changes, and writes the description. Bitbucket ticks the repository scopes it implies, which clone the repository and put the status and report on the commit.',
                'webhook' => 'only to have "Add it for me" add the webhook for every repository of the workspace: Bitbucket has no screen for that, only its API.',
            ],
            notes: [
                'Workspace access tokens come with Bitbucket\'s Premium plan. Without it, make an API token with scopes for a user account of its own at [id.atlassian.com](https://id.atlassian.com/manage-profile/security/api-tokens), with the same access to repositories, pull requests and webhooks, and fill in that account\'s email under Settings.',
                'Without the webhook scope, add the webhook to each repository by hand: see **Webhook** in the list.',
            ],
        );
    }

    public function test(Connection $connection): void
    {
        $workspace = (string) $connection->setting('workspace');

        $response = $this->request($connection)->get("https://api.bitbucket.org/2.0/repositories/{$workspace}", ['pagelen' => 1]);

        if (! $response->successful()) {
            throw new ConnectionFailed("Bitbucket answered {$response->status()}: check the token and the workspace.");
        }
    }

    public function searchRepositories(Connection $connection, string $search): array
    {
        // A quote would end the term, and nothing a repository is named has one.
        $term = str_replace(['"', '\\'], '', trim($search));

        $response = $this->request($connection)->get(
            'https://api.bitbucket.org/2.0/repositories/'.rawurlencode((string) $connection->setting('workspace')),
            array_filter([
                'q' => $term === '' ? null : "name ~ \"{$term}\"",
                'sort' => '-updated_on',
                'pagelen' => 25,
                'fields' => 'values.slug,values.name',
            ]),
        )->throw();

        $found = [];

        foreach ((array) $response->json('values') as $repository) {
            if (is_array($repository) && is_string($repository['slug'] ?? null)) {
                $found[$repository['slug']] = is_string($repository['name'] ?? null) && $repository['name'] !== $repository['slug']
                    ? "{$repository['name']} ({$repository['slug']})"
                    : $repository['slug'];
            }
        }

        return $found;
    }

    /**
     * Bitbucket has no screen for a workspace's webhooks, only this API. A hook
     * that already delivers to this address is updated, so asking twice leaves one.
     */
    public function installWebhook(Connection $connection, string $url): void
    {
        $hooks = 'https://api.bitbucket.org/2.0/workspaces/'.rawurlencode((string) $connection->setting('workspace')).'/hooks';

        $existing = null;

        foreach ((array) $this->request($connection)->get($hooks, ['pagelen' => 100])->throw()->json('values') as $hook) {
            if (is_array($hook) && ($hook['url'] ?? null) === $url && is_string($hook['uuid'] ?? null)) {
                $existing = $hook['uuid'];
            }
        }

        $payload = [
            'description' => config('app.name'),
            'url' => $url,
            'secret' => (string) $connection->webhook_secret,
            'active' => true,
            'events' => self::WEBHOOK_EVENTS,
        ];

        $existing === null
            ? $this->request($connection)->post($hooks, $payload)->throw()
            : $this->request($connection)->put($hooks.'/'.rawurlencode($existing), $payload)->throw();
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = Http::timeout(10)->connectTimeout(4)->acceptJson();

        return $connection->setting('username') !== null
            ? $request->withBasicAuth($connection->setting('username'), (string) $connection->credential('access_token'))
            : $request->withToken((string) $connection->credential('access_token'));
    }

    public function client(Repository $repository): BitbucketClient
    {
        $connection = $repository->integration;

        return new BitbucketClient(
            $repository->workspace,
            $repository->slug,
            (string) $connection->credential('access_token'),
            $connection->setting('username'),
        );
    }

    /**
     * Whether a pull request in a delivery comes from a fork, as getPullRequest()
     * says it: Bitbucket keeps no copy of a fork's branch in the repository, so
     * such a pull request is not reviewed by itself.
     *
     * @param  array<string, mixed>  $pullRequest
     * @return array<string, mixed>
     */
    protected static function withFork(array $pullRequest): array
    {
        $source = $pullRequest['source']['repository']['full_name'] ?? null;
        $destination = $pullRequest['destination']['repository']['full_name'] ?? null;

        return $pullRequest + ['from_fork' => is_string($source) && is_string($destination) && strcasecmp($source, $destination) !== 0];
    }

    public function webhookHelp(): string
    {
        return 'Starts a review on a /review comment, when a draft is marked ready, and on every new commit of a pull request. "Add it for me" adds it for every repository of the workspace. By hand it goes on each repository, under Repository settings, Webhooks, with the pull request events Created, Updated, Merged, Declined and Comment created.';
    }

    public function webhookEvent(Connection $connection, Request $request): ?WebhookEvent
    {
        $secret = (string) $connection->webhook_secret;
        $signature = $request->header('X-Hub-Signature');

        if ($secret === '' || ! is_string($signature)
            || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return null;
        }

        $payload = $request->json()->all();
        $event = (string) $request->header('X-Event-Key');

        [$workspace, $slug] = array_pad(explode('/', (string) ($payload['repository']['full_name'] ?? ''), 2), 2, '');

        $comment = $payload['comment']['content']['raw'] ?? null;

        return new WebhookEvent(
            match ($event) {
                'pullrequest:comment_created' => WebhookEvent::COMMENT_CREATED,
                'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected' => WebhookEvent::PULL_REQUEST_CHANGED,
                default => $event,
            },
            (string) $workspace,
            (string) $slug,
            is_array($payload['pullrequest'] ?? null) ? self::withFork($payload['pullrequest']) : [],
            is_string($comment) ? $comment : null,
            is_numeric($payload['comment']['id'] ?? null) ? (int) $payload['comment']['id'] : null,
            is_string($payload['comment']['user']['display_name'] ?? null) ? $payload['comment']['user']['display_name'] : null,
            is_numeric($payload['comment']['parent']['id'] ?? null) ? (int) $payload['comment']['parent']['id'] : null,
            is_string($payload['comment']['user']['account_id'] ?? null) ? $payload['comment']['user']['account_id'] : null,
            is_string($payload['comment']['user']['nickname'] ?? null) ? $payload['comment']['user']['nickname'] : null,
        );
    }
}
