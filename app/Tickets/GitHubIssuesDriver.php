<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\Fields\Secret;
use App\Connections\TicketDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use App\Rules\PublicUrl;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitHub issues. A reference is an issue number, looked up in the repository the
 * connection names, or in the one under review when it names none.
 */
class GitHubIssuesDriver implements TicketDriver
{
    public const MAX_COMMENTS = 100;

    public function label(): string
    {
        return 'GitHub issues';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('token')->label('Access token')
                ->helperText('A fine-grained token with read access to issues.'),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('repository')->label('Repository')->placeholder('owner/name')
                ->rule('regex:/^[\w.-]+\/[\w.-]+$/')
                ->helperText('Where the issues are. Empty reads them from the repository under review.'),
            TextInput::make('api_url')->label('API URL')->placeholder('https://api.github.com')->url()->rule(new PublicUrl)
                ->helperText('Only for GitHub Enterprise Server.'),
        ];
    }

    public function test(Connection $connection): void
    {
        $response = $this->request($connection)->get($this->api($connection).'/rate_limit');

        if (! $response->successful()) {
            throw new ConnectionFailed("GitHub answered {$response->status()}: check the token.");
        }
    }

    public function fetch(Connection $connection, string $reference, Repository $repository): Ticket
    {
        $number = ltrim($reference, '#');
        $name = $connection->setting('repository') ?? $repository->fullName();
        $url = $this->api($connection)."/repos/{$name}/issues/{$number}";

        $response = $this->request($connection)->get($url);

        if (! ctype_digit($number) || in_array($response->status(), [403, 404, 410], true)) {
            throw TicketNotFound::forReference("#{$number}");
        }

        // A redirect that was not followed is no issue: its body is the notice that
        // it moved, which would read as an issue with every field empty.
        if ($response->redirect()) {
            throw new ConnectionFailed("The ticket's address redirects to {$response->header('Location')}; enter that address as the API URL.");
        }

        $issue = $response->throw()->json();

        $comments = ($issue['comments'] ?? 0) > 0
            ? $this->request($connection)->get("{$url}/comments", ['per_page' => self::MAX_COMMENTS])->throw()->json()
            : [];

        return new Ticket("#{$number}", $this->markdown($issue, $comments), $issue['html_url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<int, array<string, mixed>>  $comments
     */
    protected function markdown(array $issue, array $comments): string
    {
        $lines = ["# #{$issue['number']} ".($issue['title'] ?? 'Untitled issue'), ''];

        $metadata = [
            'Link' => $issue['html_url'] ?? null,
            'Status' => $issue['state'] ?? null,
            'Author' => $issue['user']['login'] ?? null,
            'Assignees' => implode(', ', array_column($issue['assignees'] ?? [], 'login')) ?: null,
            'Labels' => implode(', ', array_column($issue['labels'] ?? [], 'name')) ?: null,
            'Milestone' => $issue['milestone']['title'] ?? null,
        ];

        foreach (array_filter($metadata) as $label => $value) {
            $lines[] = "- **{$label}:** {$value}";
        }

        $body = trim((string) ($issue['body'] ?? ''));

        if ($body !== '') {
            $lines = array_merge($lines, ['', '## Description', '', $body]);
        }

        if ($comments !== []) {
            $lines = array_merge($lines, ['', '## Comments', '']);

            foreach ($comments as $comment) {
                $author = $comment['user']['login'] ?? 'Unknown';
                $date = isset($comment['created_at']) ? ' on '.substr((string) $comment['created_at'], 0, 10) : '';
                $lines = array_merge($lines, ["### {$author}{$date}", '', trim((string) ($comment['body'] ?? '')), '']);
            }

            array_pop($lines);
        }

        return implode("\n", $lines)."\n";
    }

    protected function api(Connection $connection): string
    {
        return rtrim($connection->setting('api_url') ?? 'https://api.github.com', '/');
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = Http::withToken((string) $connection->credential('token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->connectTimeout(4);

        // An address of the tenant's own was checked to be public, and where it
        // redirects to was not, so it is followed nowhere. GitHub's own API is: it
        // answers a moved issue and a renamed repository with a redirect.
        return $connection->setting('api_url') === null ? $request : $request->withoutRedirecting();
    }
}
