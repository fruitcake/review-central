<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Connections\Fields\Secret;
use App\Connections\SourceControlDriver;
use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use App\Models\Repository;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * GitHub, read and not written to: its repositories are cloned and its pull
 * requests read, and a review of them is kept here and not posted.
 *
 * Without a token and without an owner the connection is for any public
 * repository, which is all a trial of a public pull request needs.
 *
 * Not here yet: a review that comments on GitHub, and its webhooks, so nothing on
 * GitHub starts a review by itself.
 */
class GitHubDriver implements SourceControlDriver
{
    public function label(): string
    {
        return 'GitHub (read only)';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('access_token')->label('Access token')
                ->required(false)
                ->helperText('A fine-grained token that can read the contents and pull requests of the repositories. Leave it empty for public repositories.'),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('workspace')->label('Owner')->rule('regex:/^[\w.-]+$/')
                ->helperText('The user or organisation from the address: github.com/<owner>. Leave it empty, with no token, for any public repository.'),
        ];
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

    public function installWebhook(Connection $connection, string $url): void
    {
        throw new ConnectionFailed('GitHub is only read from here for now: nothing on GitHub starts a review, so there is no webhook to add.');
    }

    public function client(Repository $repository): GitHubClient
    {
        return new GitHubClient($repository->workspace, $repository->slug, $repository->integration->credential('access_token'));
    }

    public function webhookEvent(Connection $connection, Request $request): ?WebhookEvent
    {
        return null;
    }

    protected function request(Connection $connection): PendingRequest
    {
        $request = Http::timeout(15)->connectTimeout(4)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('review-central');
        $token = $connection->credential('access_token');

        return $token === null ? $request : $request->withToken($token);
    }
}
