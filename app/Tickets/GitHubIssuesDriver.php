<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\TicketDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use App\Rules\PublicUrl;
use App\Support\SetupGuide;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GitHub issues. A reference is an issue number, looked up in the repository the
 * connection names, or in the one under review when it names none.
 */
class GitHubIssuesDriver implements ExplainsSetup, TicketDriver
{
    public const MAX_COMMENTS = 100;

    /** The issue as markdown and as HTML. */
    protected const WITH_HTML = 'application/vnd.github.full+json';

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

    /**
     * It reads an issue and its comments, and nothing else.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: 'A GitHub token for issues',
            url: 'https://github.com/settings/personal-access-tokens/new',
            linkLabel: 'New fine-grained personal access token on GitHub',
            steps: [
                'Open the link, name the token and give it an expiry date. As **Resource owner**, pick the organisation (or the user) the issues are in.',
                'Under **Repository access**, pick the repositories with the issues, or **All repositories**.',
                'Under **Repository permissions**, set **Issues** to **Read-only**.',
                'Generate the token, copy it and paste it here.',
            ],
            permissions: [
                'Issues: Read' => 'reads the issue a pull request names, and its comments.',
                'Metadata: Read' => 'GitHub adds it to every token.',
            ],
            notes: [
                'For GitHub Enterprise Server, make the token on your own server, under your **Settings → Developer settings**, and fill in its API URL under Settings.',
                'A token expires: replace it here before then.',
            ],
        );
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

        // With the HTML too, whose images carry addresses signed to be read without a token.
        $response = $this->request($connection, self::WITH_HTML)->get($url);

        if (! ctype_digit($number) || in_array($response->status(), [403, 404, 410], true)) {
            throw TicketNotFound::forReference("#{$number}");
        }

        // A redirect that was not followed is no issue: its body is the notice that
        // it moved, which would read as an issue with every field empty.
        if ($response->redirect()) {
            throw new ConnectionFailed("The ticket's address redirects to {$response->header('Location')}; enter that address as the API URL.");
        }

        $issue = $response->throw()->json();

        // GitHub numbers issues and pull requests alike, and answers a pull request
        // here too: that is a change, not a ticket.
        if (isset($issue['pull_request'])) {
            throw TicketNotFound::forReference("#{$number}");
        }

        $comments = ($issue['comments'] ?? 0) > 0
            ? $this->request($connection, self::WITH_HTML)->get("{$url}/comments", ['per_page' => self::MAX_COMMENTS])->throw()->json()
            : [];

        $attachments = $connection->setting('api_url') === null ? $this->images([$issue, ...$comments]) : [];

        return new Ticket("#{$number}", $this->markdown($issue, $comments, $attachments), $issue['html_url'] ?? null, $attachments);
    }

    public function download(Connection $connection, TicketAttachment $attachment): AttachmentFile
    {
        // Signed by GitHub for a few minutes: no token goes with it.
        return AttachmentFile::fromResponse($attachment, Http::timeout(30)->connectTimeout(4)->get(AttachmentFile::check($attachment)));
    }

    /**
     * The images in the issue and its comments, in the order they are written.
     * Only those GitHub keeps itself: an image elsewhere is shown through its
     * proxy, which is GitHub's too.
     *
     * @param  array<int, array<string, mixed>>  $posts
     * @return array<int, TicketAttachment>
     */
    protected function images(array $posts): array
    {
        $images = [];

        foreach ($posts as $post) {
            preg_match_all('/<img\b[^>]*>/i', (string) ($post['body_html'] ?? ''), $tags);

            foreach ($tags[0] as $tag) {
                $src = preg_match('/\bsrc="([^"]+)"/i', $tag, $match) === 1 ? html_entity_decode($match[1]) : null;
                $alt = preg_match('/\balt="([^"]*)"/i', $tag, $match) === 1 ? trim(html_entity_decode($match[1])) : '';

                if ($src === null || ! self::githubs($src)) {
                    continue;
                }

                $images[] = new TicketAttachment(
                    name: $alt !== '' ? $alt : (basename((string) parse_url($src, PHP_URL_PATH)) ?: 'image'),
                    downloadUrl: $src,
                    viewUrl: $post['html_url'] ?? null,
                );
            }
        }

        return $images;
    }

    /**
     * Whether GitHub serves the address itself, so reading it reaches nowhere else.
     */
    protected static function githubs(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && (str_ends_with($host, '.githubusercontent.com') || ($host === 'github.com' && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/user-attachments/')));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, TicketAttachment>  $attachments
     */
    protected function markdown(array $issue, array $comments, array $attachments = []): string
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

        $lines = array_merge($lines, TicketAttachment::section($attachments));

        return implode("\n", $lines)."\n";
    }

    protected function api(Connection $connection): string
    {
        return rtrim($connection->setting('api_url') ?? 'https://api.github.com', '/');
    }

    protected function request(Connection $connection, string $accept = 'application/vnd.github+json'): PendingRequest
    {
        $request = Http::withToken((string) $connection->credential('token'))
            ->withHeaders(['Accept' => $accept, 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->connectTimeout(4);

        // An address of the tenant's own was checked to be public, and where it
        // redirects to was not, so it is followed nowhere. GitHub's own API is: it
        // answers a moved issue and a renamed repository with a redirect.
        return $connection->setting('api_url') === null ? $request : $request->withoutRedirecting();
    }
}
