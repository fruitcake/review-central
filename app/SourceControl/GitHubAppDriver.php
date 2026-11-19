<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Connections\ConnectsWithOAuth;
use App\Connections\NeedsSetup;
use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use App\Models\Repository;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;

/**
 * GitHub, as this installation's GitHub App, installed on an organisation or a
 * user's account. The review comments and approves as <slug>[bot], its state
 * and report are a check run with the findings in the diff, and a pull request
 * that waits for approval gets a button to start it on GitHub itself.
 *
 * Connected by signing in at GitHub: that proves the person can manage the
 * app's installation on the owner, which only then becomes this connection's.
 * Their own token is revoked straight after; the connection acts as the app.
 * The app's one webhook delivers for every installation, so a connection has
 * none of its own.
 */
class GitHubAppDriver extends GitHubDriver implements ConnectsWithOAuth, NeedsSetup
{
    public function label(): string
    {
        return 'GitHub App';
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function settingFields(): array
    {
        $app = app(GitHubApp::class);

        return [
            TextInput::make('workspace')->label('Owner')->required()->rule('regex:/^[\w.-]+$/')
                ->helperText($app->configured()
                    ? 'The organisation or user the app is installed on, as in github.com/<owner>. Install it there first, at '.$app->installUrl().', then save and use "Connect" in the list.'
                    : 'This installation has no GitHub App set up: see GITHUB_APP_* in the configuration.'),
        ];
    }

    public function test(Connection $connection): void
    {
        if ($this->installation($connection) === null) {
            throw new ConnectionFailed('Not connected yet: use "Connect" in the list, after installing the app on the owner.');
        }

        parent::test($connection);
    }

    public function installWebhook(Connection $connection, string $url): void
    {
        throw new ConnectionFailed('The app delivers its own webhook: there is nothing to add.');
    }

    public function webhookHelp(): string
    {
        return 'The GitHub App delivers its own webhook, for every organisation it is installed on.';
    }

    public function client(Repository $repository): GitHubClient
    {
        return new GitHubClient($repository->workspace, $repository->slug, $this->token($repository->integration), $this->token($repository->integration) === null ? null : app(GitHubApp::class)->botAccountId() ?? '');
    }

    /**
     * The installation's token, or none before the connection is connected.
     */
    protected function token(Connection $connection): ?string
    {
        $installation = $this->installation($connection);

        return $installation === null ? null : app(GitHubApp::class)->installationToken($installation);
    }

    protected function installation(Connection $connection): ?string
    {
        return $connection->setting('installation_id');
    }

    public function oauthConfigured(): bool
    {
        return app(GitHubApp::class)->configured();
    }

    public function isSetUp(): bool
    {
        return $this->oauthConfigured();
    }

    public function oauthAuthorizeUrl(string $redirect, string $state): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('services.github_app.client_id'),
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);
    }

    public function oauthExchange(string $code, string $redirect): array
    {
        $response = Http::timeout(15)->acceptJson()->withUserAgent('review-central')->post('https://github.com/login/oauth/access_token', [
            'client_id' => config('services.github_app.client_id'),
            'client_secret' => config('services.github_app.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirect,
        ]);
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new ConnectionFailed('GitHub did not sign you in: '.($response->json('error_description') ?? $response->status()).'.');
        }

        // Kept no longer than it takes to check the installation: see oauthConnected().
        return ['access_token' => $token, 'refresh_token' => null, 'expires_in' => null];
    }

    public function oauthRefresh(string $refreshToken): array
    {
        throw new ConnectionFailed('The connection acts as the app, and has no token of a person to refresh.');
    }

    /**
     * Takes the app's installation on the connection's owner, when the person who
     * signed in can manage it and no other team has it. Their token is revoked
     * either way.
     */
    public function oauthConnected(Connection $connection, string $accessToken): array
    {
        $app = app(GitHubApp::class);

        try {
            $owner = mb_strtolower((string) $connection->setting('workspace'));
            $installation = collect($app->installationsOf($accessToken))
                ->first(fn (array $installation): bool => mb_strtolower($installation['login']) === $owner)['id'] ?? null;
        } finally {
            $app->revoke($accessToken);
        }

        if ($installation === null) {
            throw new ConnectionFailed("The app is not installed on {$connection->setting('workspace')}, or you may not manage its installation there. Install it at {$app->installUrl()} and connect again.");
        }

        $taken = Connection::query()
            ->where('driver', 'github-app')
            ->whereKeyNot($connection->getKey())
            ->get()
            ->contains(fn (Connection $other): bool => $other->setting('installation_id') === $installation);

        if ($taken) {
            throw new ConnectionFailed('Another connection already has the app\'s installation on '.$connection->setting('workspace').'.');
        }

        return ['installation_id' => $installation];
    }
}
