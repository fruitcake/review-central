<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Exceptions\ConnectionFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * This installation as a GitHub App: what it signs its own requests with, and the
 * short-lived token each installation of it on an organisation is reached with.
 *
 * The app's private key signs a JSON Web Token that names the app for ten
 * minutes; GitHub trades that for an installation's token, which lasts an hour.
 * It is kept for a little less, so a review never starts on one about to expire.
 */
class GitHubApp
{
    protected const API = 'https://api.github.com';

    /** How long an installation's token is used: GitHub gives it an hour. */
    protected const TOKEN_SECONDS = 50 * 60;

    public function configured(): bool
    {
        return filled(config('services.github_app.id'))
            && filled(config('services.github_app.slug'))
            && filled(config('services.github_app.client_id'))
            && filled(config('services.github_app.client_secret'))
            && filled(config('services.github_app.private_key'));
    }

    public function id(): string
    {
        return (string) config('services.github_app.id');
    }

    public function slug(): string
    {
        return (string) config('services.github_app.slug');
    }

    /**
     * Where an organisation installs the app.
     */
    public function installUrl(): string
    {
        return 'https://github.com/apps/'.rawurlencode($this->slug()).'/installations/new';
    }

    /**
     * The token an installation is reached with, while it lasts.
     *
     * @throws ConnectionFailed when GitHub does not give one, as for an installation that is gone
     */
    public function installationToken(string $installation): string
    {
        return Cache::remember("github-app-token:{$this->id()}:{$installation}", self::TOKEN_SECONDS, function () use ($installation): string {
            $response = $this->asApp()->post(self::API.'/app/installations/'.rawurlencode($installation).'/access_tokens');
            $token = $response->json('token');

            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new ConnectionFailed("GitHub gave no token for the app's installation {$installation} ({$response->status()}): it may have been removed from the organisation.");
            }

            return $token;
        });
    }

    /**
     * The account the app comments as, <slug>[bot], by its id: how its own
     * comments are told from anyone else's.
     */
    public function botAccountId(): ?string
    {
        return Cache::rememberForever("github-app-bot:{$this->slug()}", function (): ?string {
            $id = Http::withUserAgent('review-central')->acceptJson()->get(self::API.'/users/'.rawurlencode($this->slug().'[bot]'))->json('id');

            return is_numeric($id) ? (string) $id : null;
        });
    }

    /**
     * The installations of the app the person whose token this is can reach, each
     * by its id, the login of the account it is on and whether that is an
     * organisation. Reaching one is not managing it: a member who may read one
     * of its repositories reaches it too. See manages().
     *
     * @return list<array{id: string, login: string, organisation: bool}>
     *
     * @throws ConnectionFailed
     */
    public function installationsOf(string $userToken): array
    {
        $response = $this->request()->withToken($userToken)->get(self::API.'/user/installations', ['per_page' => 100]);

        if (! $response->successful()) {
            throw new ConnectionFailed("GitHub did not say which installations of the app you can reach ({$response->status()}).");
        }

        $installations = [];

        foreach ((array) $response->json('installations') as $installation) {
            if (is_array($installation) && is_numeric($installation['id'] ?? null) && is_string($installation['account']['login'] ?? null)) {
                $installations[] = [
                    'id' => (string) $installation['id'],
                    'login' => $installation['account']['login'],
                    'organisation' => ($installation['account']['type'] ?? null) === 'Organization',
                ];
            }
        }

        return $installations;
    }

    /**
     * Whether the person whose token this is may connect the installation: it is
     * on their own account, or they are an owner of its organisation. Asked of
     * GitHub as the installation, which needs the app's Members permission.
     *
     * @param  array{id: string, login: string, organisation: bool}  $installation
     *
     * @throws ConnectionFailed
     */
    public function manages(string $userToken, array $installation): bool
    {
        $login = $this->request()->withToken($userToken)->get(self::API.'/user')->json('login');

        if (! is_string($login) || $login === '') {
            throw new ConnectionFailed('GitHub did not say who signed in.');
        }

        if (! $installation['organisation']) {
            return strcasecmp($login, $installation['login']) === 0;
        }

        $membership = $this->request()->withToken($this->installationToken($installation['id']))
            ->get(self::API.'/orgs/'.rawurlencode($installation['login']).'/memberships/'.rawurlencode($login));

        if ($membership->status() === 403) {
            throw new ConnectionFailed('The app may not see who owns '.$installation['login'].': give it the organisation permission Members (read) in its settings, and have the organisation accept that.');
        }

        return $membership->successful() && $membership->json('state') === 'active' && $membership->json('role') === 'admin';
    }

    /**
     * Revokes a person's token once it proved what it had to: the connection acts
     * as the app, never as them.
     */
    public function revoke(string $userToken): void
    {
        rescue(fn () => $this->request()
            ->withBasicAuth((string) config('services.github_app.client_id'), (string) config('services.github_app.client_secret'))
            ->send('DELETE', self::API.'/applications/'.rawurlencode((string) config('services.github_app.client_id')).'/token', ['json' => ['access_token' => $userToken]]), null, false);
    }

    /**
     * Whether a webhook delivery is signed with the app's webhook secret.
     */
    public function signed(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.github_app.webhook_secret');

        return $secret !== '' && is_string($signature) && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    /**
     * The app's own JSON Web Token, RS256, valid for ten minutes and a minute in
     * the past for a clock that runs ahead of GitHub's.
     */
    public function jwt(): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        if ($key === false) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY is not a private key in PEM, nor the path of one.');
        }

        $encode = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$encode((string) json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $this->id()]));

        openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.$encode((string) $signature);
    }

    protected function privateKey(): string
    {
        $key = str_replace('\n', "\n", (string) config('services.github_app.private_key'));

        if (! str_contains($key, '-----BEGIN')) {
            $path = str_starts_with($key, '/') ? $key : base_path($key);
            $key = is_file($path) ? (string) file_get_contents($path) : '';
        }

        return $key;
    }

    protected function asApp(): PendingRequest
    {
        return $this->request()->withToken($this->jwt());
    }

    protected function request(): PendingRequest
    {
        return Http::timeout(15)->connectTimeout(4)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('review-central');
    }
}
