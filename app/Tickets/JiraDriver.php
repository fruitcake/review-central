<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\ConnectsWithOAuth;
use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\Integrations;
use App\Connections\OAuthTokens;
use App\Connections\TicketDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use App\Support\SetupGuide;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Jira Cloud, read with an account's API token.
 */
class JiraDriver implements ConnectsWithOAuth, ExplainsSetup, TicketDriver
{
    public const MAX_COMMENTS = 100;

    protected const ISSUE_FIELDS = 'summary,description,status,issuetype,priority,assignee,reporter,duedate,labels,project,parent,subtasks,attachment';

    public function label(): string
    {
        return 'Jira';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('api_token')->label('API token')
                // With this installation registered at Atlassian, signing in there does instead.
                ->required(fn (string $operation): bool => $operation === 'create' && ! Integrations::offered($this))
                ->helperText(fn (): string => Integrations::offered($this)
                    ? 'Created at id.atlassian.com, under Security, API tokens. Or leave it empty, save, and use "Connect" in the list to sign in at Atlassian instead.'
                    : 'Created at id.atlassian.com, under Security, API tokens. '.Integrations::unavailable($this, 'Atlassian')),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('site')->label('Site')->placeholder('example.atlassian.net')->required()
                ->rule('regex:/^(https?:\/\/)?[a-z0-9-]+\.atlassian\.net\/?$/i'),
            TextInput::make('email')->label('Account email')->email()
                ->required(fn (): bool => ! Integrations::offered($this))
                ->helperText('The account the API token belongs to. Not needed when you sign in at Atlassian instead.'),
        ];
    }

    /**
     * A token without scopes, because the site is called directly with it: an
     * Atlassian token with scopes is accepted only through api.atlassian.com,
     * which the connection uses only when it signed in there.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: 'An Atlassian API token for Jira',
            url: 'https://id.atlassian.com/manage-profile/security/api-tokens',
            linkLabel: 'API tokens of your Atlassian account',
            steps: [
                'Sign in at Atlassian as the account the review reads Jira as: one that can browse the projects the pull requests name.',
                'Choose **Create API token** (not the one with scopes), name it, pick an expiry date and create it.',
                'Copy the token and paste it here.',
                'Under Settings, fill in the site, such as `example.atlassian.net`, and the account\'s email.',
            ],
            permissions: [
                'Browse projects' => 'the Jira permission the account needs on each project, to read an issue, its subtasks and its comments. The token can do what its account can; nothing is written.',
            ],
            notes: [
                'A token with scopes does not work here: Atlassian takes one only through api.atlassian.com, and this connection calls your site directly.',
                'A token expires, after a year at most: replace it here before then. **Test** in the list says whether it still works.',
                ...(Integrations::offered($this) ? ['Or leave the token and email empty, save, and use **Connect** in the list to sign in at Atlassian instead.'] : []),
            ],
        );
    }

    public function test(Connection $connection): void
    {
        $response = $this->request($connection)->get($this->url($connection, 'myself'));

        if (! $response->successful()) {
            throw new ConnectionFailed("Jira answered {$response->status()}: check the site, the email and the token.");
        }
    }

    public function fetch(Connection $connection, string $reference, Repository $repository): Ticket
    {
        $key = strtoupper($reference);
        $response = $this->request($connection)->get($this->url($connection, "issue/{$key}"), ['fields' => self::ISSUE_FIELDS]);

        if (in_array($response->status(), [403, 404], true)) {
            throw TicketNotFound::forReference($key);
        }

        $issue = $response->throw()->json();

        $result = $this->request($connection)
            ->get($this->url($connection, "issue/{$key}/comment"), ['orderBy' => '-created', 'maxResults' => self::MAX_COMMENTS])
            ->throw()
            ->json();

        $comments = array_reverse($result['comments'] ?? []);

        return new Ticket(
            $key,
            $this->markdown($this->host($connection), $issue, $comments, (int) ($result['total'] ?? count($comments))),
            "https://{$this->host($connection)}/browse/{$key}",
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<int, array<string, mixed>>  $comments  the newest, oldest first
     */
    protected function markdown(string $host, array $issue, array $comments, int $total): string
    {
        $fields = $issue['fields'] ?? [];
        $link = fn (string $key): string => "https://{$host}/browse/{$key}";

        $lines = ["# {$issue['key']} ".($fields['summary'] ?? 'Untitled issue'), ''];

        $metadata = [
            'Link' => $link($issue['key']),
            'Status' => $fields['status']['name'] ?? null,
            'Type' => $fields['issuetype']['name'] ?? null,
            'Priority' => $fields['priority']['name'] ?? null,
            'Assignee' => $fields['assignee']['displayName'] ?? null,
            'Reporter' => $fields['reporter']['displayName'] ?? null,
            'Due' => $fields['duedate'] ?? null,
            'Project' => $fields['project']['name'] ?? null,
            'Labels' => implode(', ', $fields['labels'] ?? []) ?: null,
            'Parent' => isset($fields['parent']['key']) ? trim($fields['parent']['key'].' '.($fields['parent']['fields']['summary'] ?? '')) : null,
        ];

        foreach (array_filter($metadata) as $label => $value) {
            $lines[] = "- **{$label}:** {$value}";
        }

        // Version 2 of the API returns the description as wiki markup, not a document tree.
        $description = trim((string) ($fields['description'] ?? ''));

        if ($description !== '') {
            $lines = array_merge($lines, ['', '## Description', '', $description]);
        }

        if (($fields['subtasks'] ?? []) !== []) {
            $lines = array_merge($lines, ['', '## Subtasks', '']);

            foreach ($fields['subtasks'] as $subtask) {
                $done = ($subtask['fields']['status']['statusCategory']['key'] ?? null) === 'done';
                $status = isset($subtask['fields']['status']['name']) ? " ({$subtask['fields']['status']['name']})" : '';
                $lines[] = '- ['.($done ? 'x' : ' ')."] {$subtask['key']} ".($subtask['fields']['summary'] ?? 'Untitled').$status;
            }
        }

        if ($comments !== []) {
            $lines = array_merge($lines, ['', '## Comments', '']);
            $older = $total - count($comments);

            if ($older > 0) {
                $lines = array_merge($lines, ["The {$older} oldest comments are left out.", '']);
            }

            foreach ($comments as $comment) {
                $author = $comment['author']['displayName'] ?? 'Unknown';
                $date = isset($comment['created']) ? ' on '.substr((string) $comment['created'], 0, 10) : '';
                $lines = array_merge($lines, ["### {$author}{$date}", '', trim((string) ($comment['body'] ?? '')), '']);
            }

            array_pop($lines);
        }

        if (($fields['attachment'] ?? []) !== []) {
            $lines = array_merge($lines, ['', '## Attachments', '', 'Listed only: the files themselves are not read.', '']);

            foreach ($fields['attachment'] as $attachment) {
                $lines[] = '- '.($attachment['filename'] ?? 'Unnamed file').(isset($attachment['mimeType']) ? " ({$attachment['mimeType']})" : '');
            }
        }

        return implode("\n", $lines)."\n";
    }

    protected function host(Connection $connection): string
    {
        return strtolower(trim((string) preg_replace('#^https?://#i', '', (string) $connection->setting('site')), '/'));
    }

    protected function url(Connection $connection, string $path): string
    {
        // Signed in at Atlassian, the site is reached through Atlassian's own
        // gateway, by the id it has there.
        return OAuthTokens::has($connection)
            ? "https://api.atlassian.com/ex/jira/{$connection->setting('cloud_id')}/rest/api/2/{$path}"
            : "https://{$this->host($connection)}/rest/api/2/{$path}";
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = OAuthTokens::has($connection)
            ? Http::withToken(app(OAuthTokens::class)->token($connection))
            : Http::withBasicAuth((string) $connection->setting('email'), (string) $connection->credential('api_token'));

        return $request->acceptJson()->timeout(20)->connectTimeout(4);
    }

    public function oauthIntegration(): string
    {
        return Integrations::JIRA;
    }

    public function oauthConfigured(): bool
    {
        return filled(config('services.atlassian.client_id')) && filled(config('services.atlassian.client_secret'));
    }

    public function oauthAuthorizeUrl(string $redirect, string $state): string
    {
        return 'https://auth.atlassian.com/authorize?'.http_build_query([
            'audience' => 'api.atlassian.com',
            'client_id' => config('services.atlassian.client_id'),
            // Reading issues and who they are assigned to, and a refresh token
            // to keep reading them with.
            'scope' => 'read:jira-work read:jira-user offline_access',
            'redirect_uri' => $redirect,
            'state' => $state,
            'response_type' => 'code',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function oauthExchange(string $code, string $redirect): array
    {
        return $this->oauthToken(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
    }

    public function oauthRefresh(string $refreshToken): array
    {
        return $this->oauthToken(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Which of the person's sites this connection is for: the one it names. A
     * token may reach several, and a call goes to one by its id.
     */
    public function oauthConnected(Connection $connection, string $accessToken): array
    {
        $sites = Http::withToken($accessToken)->acceptJson()->timeout(20)->connectTimeout(4)
            ->get('https://api.atlassian.com/oauth/token/accessible-resources');
        $host = $this->host($connection);

        foreach ($sites->successful() ? (array) $sites->json() : [] as $site) {
            $url = is_array($site) && is_string($site['url'] ?? null) ? strtolower(trim((string) preg_replace('#^https?://#i', '', $site['url']), '/')) : null;

            if ($url === $host && is_string($site['id'] ?? null)) {
                return ['cloud_id' => $site['id']];
            }
        }

        throw new ConnectionFailed("The Atlassian account that signed in was given no access to {$host}. Connect again and choose that site.");
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    protected function oauthToken(array $grant): array
    {
        $response = Http::asJson()->acceptJson()->timeout(20)->connectTimeout(4)->post('https://auth.atlassian.com/oauth/token', $grant + [
            'client_id' => (string) config('services.atlassian.client_id'),
            'client_secret' => (string) config('services.atlassian.client_secret'),
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new ConnectionFailed("Atlassian answered {$response->status()} to the sign-in: connect again.");
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_in' => is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        ];
    }
}
