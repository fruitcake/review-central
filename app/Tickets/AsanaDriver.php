<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\ConnectsWithOAuth;
use App\Connections\Fields\Secret;
use App\Connections\OAuthTokens;
use App\Connections\TicketDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Asana, read with a personal access token or a service account's. A reference is
 * a task's custom ID (`PROJ-123`), its id, or the number its name starts with
 * (`#1234 - Export the orders`), for a workspace that numbers its tasks by hand.
 */
class AsanaDriver implements ConnectsWithOAuth, TicketDriver
{
    protected const API = 'https://app.asana.com/api/1.0';

    protected const TASK_FIELDS = 'name,notes,completed,due_on,permalink_url,assignee.name,projects.name,parent.name,custom_fields.name,custom_fields.display_value';

    public function label(): string
    {
        return 'Asana';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('access_token')->label('Access token')
                // With this installation registered at Asana, signing in there does instead.
                ->required(fn (string $operation): bool => $operation === 'create' && ! $this->oauthConfigured())
                ->helperText($this->oauthConfigured()
                    ? 'A personal access token, or a service account token. Or leave it empty, save, and use "Connect" in the list to sign in at Asana instead.'
                    : 'A personal access token, or a service account token.'),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('workspace')->label('Workspace ID')->required()->rule('regex:/^\d+$/')
                ->helperText('The number of the Asana workspace the custom IDs are looked up in.'),
        ];
    }

    public function test(Connection $connection): void
    {
        $response = $this->request($connection)->get(self::API.'/workspaces/'.$connection->setting('workspace'));

        if (! $response->successful()) {
            throw new ConnectionFailed("Asana answered {$response->status()}: check the token and the workspace ID.");
        }
    }

    public function fetch(Connection $connection, string $reference, Repository $repository): Ticket
    {
        $task = $this->findTask($connection, $reference);
        $gid = (string) $task['gid'];

        $subtasks = $this->request($connection)
            ->get(self::API."/tasks/{$gid}/subtasks", ['opt_fields' => 'name,completed,assignee.name', 'limit' => 100])
            ->throw()
            ->json('data') ?? [];

        return new Ticket($reference, $this->markdown($task, $subtasks, $this->comments($connection, $gid)), $task['permalink_url'] ?? null);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TicketNotFound
     */
    protected function findTask(Connection $connection, string $reference): array
    {
        // A number too short for a task id is no custom ID either, which has a
        // prefix: it is the number the task's name starts with.
        if (preg_match('/^\d{1,11}$/', $reference)) {
            return $this->findTaskByNumber($connection, $reference);
        }

        // A task id is long; anything else is a custom ID.
        $url = preg_match('/^\d{12,}$/', $reference)
            ? self::API."/tasks/{$reference}"
            : self::API.'/workspaces/'.$connection->setting('workspace').'/tasks/custom_id/'.rawurlencode($reference);

        $response = $this->request($connection)->get($url, ['opt_fields' => self::TASK_FIELDS]);

        if (in_array($response->status(), [403, 404], true)) {
            throw TicketNotFound::forReference($reference);
        }

        $task = $response->throw()->json('data');

        if (! is_array($task) || ! isset($task['gid'])) {
            throw TicketNotFound::forReference($reference);
        }

        return $task;
    }

    /**
     * The task whose name starts with the number: "#1234 - Export the orders".
     *
     * Asana has no lookup by name, so it is searched for and the answers are held
     * to the number: a search for 1234 also finds #12345 and a task that mentions it.
     *
     * @return array<string, mixed>
     *
     * @throws TicketNotFound
     */
    protected function findTaskByNumber(Connection $connection, string $number): array
    {
        $workspace = $connection->setting('workspace');
        $named = fn (array $task): bool => preg_match('/^\s*#?'.$number.'(?!\d)/', (string) ($task['name'] ?? '')) === 1;

        // The typeahead is on every plan; the search is not, and finds what the
        // typeahead ranks too low to return.
        $lookups = [
            [self::API."/workspaces/{$workspace}/typeahead", ['resource_type' => 'task', 'query' => $number, 'count' => 50, 'opt_fields' => 'name']],
            [self::API."/workspaces/{$workspace}/tasks/search", ['text' => $number, 'limit' => 50, 'opt_fields' => 'name']],
        ];

        foreach ($lookups as $index => [$url, $query]) {
            $response = $this->request($connection)->get($url, $query);

            // The search answers 402 on a plan without it: then it found nothing.
            // Any other failure is one, and is reported as such.
            if ($index > 0 && $response->status() === 402) {
                break;
            }

            foreach ((array) ($response->throw()->json('data') ?? []) as $task) {
                if (is_array($task) && isset($task['gid']) && $named($task)) {
                    return $this->findTask($connection, (string) $task['gid']);
                }
            }
        }

        throw TicketNotFound::forReference($number);
    }

    /**
     * What people wrote on the task, without the system's own history lines.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function comments(Connection $connection, string $gid): array
    {
        $stories = [];
        $query = ['opt_fields' => 'type,text,created_at,created_by.name', 'limit' => 100];

        do {
            $response = $this->request($connection)->get(self::API."/tasks/{$gid}/stories", $query)->throw()->json();
            array_push($stories, ...($response['data'] ?? []));
            $query['offset'] = $response['next_page']['offset'] ?? null;
        } while ($query['offset'] && count($stories) < 500);

        return array_values(array_filter($stories, fn (array $story): bool => ($story['type'] ?? null) === 'comment'));
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<int, array<string, mixed>>  $subtasks
     * @param  array<int, array<string, mixed>>  $comments
     */
    protected function markdown(array $task, array $subtasks, array $comments): string
    {
        $lines = ['# '.($task['name'] ?? 'Untitled task'), ''];

        $metadata = [
            'Link' => $task['permalink_url'] ?? null,
            'Status' => ($task['completed'] ?? false) ? 'completed' : 'open',
            'Assignee' => $task['assignee']['name'] ?? null,
            'Due' => $task['due_on'] ?? null,
            'Projects' => implode(', ', array_filter(array_column($task['projects'] ?? [], 'name'))) ?: null,
            'Parent' => $task['parent']['name'] ?? null,
        ];

        foreach (array_filter($metadata) as $label => $value) {
            $lines[] = "- **{$label}:** {$value}";
        }

        foreach ($task['custom_fields'] ?? [] as $field) {
            $value = $field['display_value'] ?? null;

            if ($value !== null && $value !== '' && isset($field['name'])) {
                $lines[] = "- **{$field['name']}:** {$value}";
            }
        }

        $notes = trim((string) ($task['notes'] ?? ''));

        if ($notes !== '') {
            $lines = array_merge($lines, ['', '## Description', '', $notes]);
        }

        if ($subtasks !== []) {
            $lines = array_merge($lines, ['', '## Subtasks', '']);

            foreach ($subtasks as $subtask) {
                $assignee = isset($subtask['assignee']['name']) ? " ({$subtask['assignee']['name']})" : '';
                $lines[] = '- ['.(($subtask['completed'] ?? false) ? 'x' : ' ').'] '.($subtask['name'] ?? 'Untitled').$assignee;
            }
        }

        if ($comments !== []) {
            $lines = array_merge($lines, ['', '## Comments', '']);

            foreach ($comments as $comment) {
                $author = $comment['created_by']['name'] ?? 'Unknown';
                $date = isset($comment['created_at']) ? ' on '.substr((string) $comment['created_at'], 0, 10) : '';
                $lines = array_merge($lines, ["### {$author}{$date}", '', trim((string) ($comment['text'] ?? '')), '']);
            }

            array_pop($lines);
        }

        return implode("\n", $lines)."\n";
    }

    protected function request(Connection $connection): PendingRequest
    {
        // Signed in at Asana where it was, a pasted token otherwise.
        $token = OAuthTokens::has($connection) ? app(OAuthTokens::class)->token($connection) : (string) $connection->credential('access_token');

        return Http::withToken($token)->acceptJson()->timeout(20)->connectTimeout(4);
    }

    public function oauthConfigured(): bool
    {
        return filled(config('services.asana.client_id')) && filled(config('services.asana.client_secret'));
    }

    public function oauthAuthorizeUrl(string $redirect, string $state): string
    {
        return 'https://app.asana.com/-/oauth_authorize?'.http_build_query(array_filter([
            'client_id' => config('services.asana.client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'state' => $state,
            // An app registered with scopes has to name them; one with full
            // permissions is refused when it does. So this is the app's to set.
            'scope' => config('services.asana.scope'),
        ], fn (mixed $value): bool => filled($value)), '', '&', PHP_QUERY_RFC3986);
    }

    public function oauthExchange(string $code, string $redirect): array
    {
        return $this->oauthToken(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
    }

    public function oauthRefresh(string $refreshToken): array
    {
        return $this->oauthToken(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function oauthConnected(Connection $connection, string $accessToken): array
    {
        return [];
    }

    /**
     * @param  array<string, string>  $grant
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    protected function oauthToken(array $grant): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->connectTimeout(4)->post('https://app.asana.com/-/oauth_token', $grant + [
            'client_id' => (string) config('services.asana.client_id'),
            'client_secret' => (string) config('services.asana.client_secret'),
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new ConnectionFailed("Asana answered {$response->status()} to the sign-in: connect again.");
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_in' => is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        ];
    }
}
