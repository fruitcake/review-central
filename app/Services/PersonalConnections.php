<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Filament\Auth\EditProfile;
use App\Models\PersonalConnection;
use App\Models\Repository;
use App\Models\User;
use App\SourceControl\BitbucketClient;
use App\SourceControl\GitHubApp;
use App\SourceControl\GitHubClient;
use App\SourceControl\OpensPullRequests;
use App\SourceControl\ReadOnlyClient;
use App\SourceControl\SourceControl;
use App\Support\SecretFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider;
use Throwable;

/**
 * A user's own account at Bitbucket or GitHub, connected on their profile, so that
 * what they write on a pull request through Review Central is written as them
 * rather than as the tenant's connection. Kept apart from the connections, which
 * the reviews use: this one is only ever used for its user, and only to write what
 * they wrote.
 *
 * On GitHub the account is reached through this installation's GitHub App, as a
 * token GitHub gives the app for the user: it writes as them, with the app's badge
 * next to their name, and only on repositories the app is installed on.
 */
class PersonalConnections
{
    public const BITBUCKET = 'bitbucket';

    public const GITHUB = 'github';

    /**
     * The hosts, with their names for people.
     *
     * @var array<string, string>
     */
    public const HOSTS = [self::BITBUCKET => 'Bitbucket', self::GITHUB => 'GitHub'];

    /**
     * The repository connections whose repositories a host's account writes on: a
     * GitHub App's token for a user reaches only where the app is installed.
     *
     * @var array<string, string>
     */
    protected const DRIVERS = ['bitbucket' => self::BITBUCKET, 'github-app' => self::GITHUB];

    /**
     * Who the account is, and commenting on pull requests, which `pullrequest`
     * allows without merging or writing to the repository. Bitbucket gives a
     * token the permissions of its OAuth client whatever is asked, so the client
     * itself should have no more.
     *
     * @var array<int, string>
     */
    public const BITBUCKET_SCOPES = ['account', 'pullrequest'];

    /**
     * What connecting and signing in both ask Bitbucket for: the same both ways,
     * so a consent given for the one is not asked again for the other.
     *
     * @var array<int, string>
     */
    public const BITBUCKET_ASKED = ['account', 'email', 'pullrequest'];

    /**
     * Scopes that include one of BITBUCKET_SCOPES, as Bitbucket names a token's.
     *
     * @var array<string, string>
     */
    protected const IMPLIED = ['pullrequest:write' => 'pullrequest', 'account:write' => 'account'];

    /**
     * Whether this installation is registered at the host, so users can connect:
     * a Bitbucket OAuth client, or a GitHub App.
     */
    public static function available(string $host): bool
    {
        return match ($host) {
            self::BITBUCKET => filled(config('services.bitbucket.client_id')) && filled(config('services.bitbucket.client_secret')),
            self::GITHUB => app(GitHubApp::class)->configured(),
            default => false,
        };
    }

    /**
     * Signing in at the host, coming back through its one callback, which
     * connecting and signing in share. Null where the installation is not
     * registered there.
     */
    public static function provider(string $host): ?AbstractProvider
    {
        if (! self::available($host)) {
            return null;
        }

        $redirect = route('connections.personal.callback', ['host' => $host]);

        // The GitHub App's credentials, which Socialite does not look for.
        $provider = $host === self::GITHUB
            ? Socialite::buildProvider(GithubProvider::class, [
                'client_id' => config('services.github_app.client_id'),
                'client_secret' => config('services.github_app.client_secret'),
                'redirect' => $redirect,
            ])
            : Socialite::driver($host);

        if (! $provider instanceof AbstractProvider) {
            return null;
        }

        // A GitHub App has its permissions, not scopes: it ignores any asked for.
        return $host === self::BITBUCKET ? $provider->redirectUrl($redirect)->setScopes(self::BITBUCKET_ASKED) : $provider->redirectUrl($redirect);
    }

    public function of(User $user, string $host): ?PersonalConnection
    {
        return PersonalConnection::query()->where('user_id', $user->getKey())->where('host', $host)->first();
    }

    /**
     * Keeps the account the user signed in as at the host, in place of any they
     * had there. It tells whose turn a pull request is by itself, so it is taken
     * off the accounts they listed.
     *
     * @param  array<int, string>  $scopes  what Bitbucket granted; none on GitHub, whose app has permissions instead
     */
    public function connect(User $user, string $host, ProviderUser $account, string $accessToken, ?string $refreshToken, ?int $expiresIn, array $scopes = []): PersonalConnection
    {
        $raw = method_exists($account, 'getRaw') ? (array) $account->getRaw() : [];
        $accountId = is_string($raw['account_id'] ?? null) && $raw['account_id'] !== '' ? $raw['account_id'] : (string) $account->getId();
        $nickname = $account->getNickname() ?? (is_string($raw['nickname'] ?? null) ? $raw['nickname'] : null);

        $connection = PersonalConnection::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'host' => $host],
            [
                'account_id' => $accountId,
                'nickname' => $nickname,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresIn === null ? null : now()->addSeconds($expiresIn),
                'scopes' => array_values(array_filter($scopes)),
            ],
        );

        // Counted by itself now: listed as well, it would stay after disconnecting.
        $own = array_map('mb_strtolower', array_filter([$accountId, $nickname]));
        $user->forceFill(['source_accounts' => array_values(array_filter($user->source_accounts ?? [], fn (string $account): bool => ! in_array(mb_strtolower(trim($account)), $own, true)))])->save();
        $user->unsetRelation('personalConnections');

        return $connection;
    }

    /**
     * The scopes Bitbucket granted a token, as it names them on every answer:
     * Socialite does not read them from where Bitbucket puts them.
     *
     * @return array<int, string>
     */
    public function grantedScopes(string $accessToken): array
    {
        $header = rescue(fn (): string => Http::withToken($accessToken)->timeout(10)->get('https://api.bitbucket.org/2.0/user', ['fields' => 'uuid'])->header('X-OAuth-Scopes'), '', false);

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }

    /**
     * The account's confirmed addresses, the primary one first. An account may
     * have several, and the one its person has here need not be the primary;
     * where the host lists none, the primary one the provider named, if any.
     *
     * @return array<int, string> lower case
     */
    public function verifiedEmails(string $host, string $accessToken, ?string $primary): array
    {
        $listed = $accessToken === '' ? [] : rescue(fn (): array => match ($host) {
            self::GITHUB => collect((array) Http::withToken($accessToken)->acceptJson()->timeout(10)->get('https://api.github.com/user/emails', ['per_page' => 100])->throw()->json())
                ->filter(fn (mixed $email): bool => is_array($email) && ($email['verified'] ?? false) === true && is_string($email['email'] ?? null))
                ->sortByDesc(fn (array $email): bool => ($email['primary'] ?? false) === true)
                ->pluck('email')
                ->all(),
            self::BITBUCKET => collect((array) Http::withToken($accessToken)->timeout(10)->get('https://api.bitbucket.org/2.0/user/emails', ['pagelen' => 100])->throw()->json('values'))
                ->filter(fn (mixed $email): bool => is_array($email) && ($email['is_confirmed'] ?? false) === true && is_string($email['email'] ?? null))
                ->sortByDesc(fn (array $email): bool => ($email['is_primary'] ?? false) === true)
                ->pluck('email')
                ->all(),
            default => [],
        }, [], false);

        return collect([$primary, ...$listed])
            ->filter(fn (mixed $email): bool => is_string($email) && str_contains($email, '@'))
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the user's account at the host signs them in, connected or not.
     * The host's name is the sign-in provider's.
     */
    public function signsIn(User $user, string $host): bool
    {
        return $user->identities()->where('provider', $host)->exists();
    }

    /**
     * Forgets the user's account at the host: its tokens, and that it signs them
     * in. GitHub is told to forget the app's token for them as well.
     */
    public function disconnect(User $user, string $host): void
    {
        $connection = $this->of($user, $host);

        if ($connection !== null && $host === self::GITHUB) {
            app(GitHubApp::class)->revoke($connection->access_token);
        }

        $connection?->delete();
        $user->identities()->where('provider', $host)->delete();
        $user->unsetRelation('personalConnections');
    }

    /**
     * Why the user's account at the host cannot write right now, in a sentence,
     * or null when it can: none connected, a token the host no longer renews, or
     * one Bitbucket granted before writing on pull requests was asked for.
     */
    public function problem(User $user, string $host): ?string
    {
        $connection = $this->of($user, $host);
        $name = self::HOSTS[$host] ?? $host;

        return match (true) {
            $connection === null => "Your {$name} account is not connected.",
            $host === self::BITBUCKET && ! $this->canComment($connection->scopes ?? []) => 'Your Bitbucket account was connected without the right to comment on pull requests: connect it again on your profile.',
            $this->accessToken($connection) === null => "{$name} no longer accepts your connection: connect your account again on your profile.",
            default => null,
        };
    }

    /**
     * Whether a token with these scopes says who it is and may comment.
     *
     * @param  array<int, string>  $scopes
     */
    protected function canComment(array $scopes): bool
    {
        $held = [...$scopes, ...array_values(array_intersect_key(self::IMPLIED, array_flip($scopes)))];

        return array_diff(self::BITBUCKET_SCOPES, $held) === [];
    }

    /**
     * The repository's pull requests as the user writes on them, or null where
     * their own account cannot: a repository their host's account does not reach
     * (on GitHub, one not reviewed through the GitHub App), no account or one
     * that cannot write, or a connection set to write nothing.
     */
    public function writerFor(User $user, Repository $repository): ?SourceControl
    {
        $host = self::DRIVERS[(string) $repository->integration?->driver] ?? null;

        if ($host === null || $this->problem($user, $host) !== null) {
            return null;
        }

        // A connection set to keep its reviews here writes nothing, as in Repository::sourceControl().
        if ($repository->integration?->setting(ReadOnlyClient::SETTING) === ReadOnlyClient::READ) {
            return null;
        }

        $token = (string) $this->accessToken($this->of($user, $host));
        $client = $host === self::GITHUB
            ? new GitHubClient($repository->workspace, $repository->slug, $token)
            : new BitbucketClient($repository->workspace, $repository->slug, $token);

        // What they write passes the tenant's secrets first, as the review's does.
        return $client->keepingOut(SecretFilter::forTenant($repository->tenant_id));
    }

    /**
     * The repository's pull requests as the user opens and edits them, with the
     * account they do it as: the one whose pull requests they may edit.
     *
     * @return array{client: SourceControl&OpensPullRequests, account_id: string}
     *
     * @throws ActionRefused with why not, and what to do about it
     */
    public function authorFor(User $user, Repository $repository): array
    {
        $host = self::DRIVERS[(string) $repository->integration?->driver] ?? null;
        $profile = EditProfile::getUrl(panel: 'app', tenant: $repository->tenant);

        if ($host === null) {
            throw new ActionRefused("Pull requests are opened as you on Bitbucket, and on GitHub through the GitHub App; {$repository->fullName()} is reached otherwise. Open it on the host yourself.");
        }

        $name = self::HOSTS[$host];

        if (! self::available($host)) {
            throw new ActionRefused("This installation is not registered at {$name} for people to act as themselves. Open the pull request on {$name} yourself.");
        }

        if (($problem = $this->problem($user, $host)) !== null) {
            throw new ActionRefused("{$problem} Connect it under \"Bitbucket and GitHub\" on your profile: {$profile}");
        }

        $connection = $this->of($user, $host);

        if ($connection === null) {
            throw new ActionRefused("Your {$name} account is not connected. Connect it on your profile: {$profile}");
        }

        // Bitbucket gives a token what its OAuth client may, and writing on pull requests
        // is more than commenting: the client has to allow it, and the user to connect after.
        if ($host === self::BITBUCKET && ! in_array('pullrequest:write', $connection->scopes ?? [], true)) {
            throw new ActionRefused("Your Bitbucket account was connected without the right to open or edit pull requests. Once this installation's OAuth client has \"Pull requests: Write\", connect it again on your profile: {$profile}");
        }

        if ($repository->integration?->setting(ReadOnlyClient::SETTING) === ReadOnlyClient::READ) {
            throw new ActionRefused("{$repository->fullName()} is set to write nothing on its pull requests. Open the pull request on {$name} yourself.");
        }

        $token = (string) $this->accessToken($connection);
        $client = $host === self::GITHUB
            ? new GitHubClient($repository->workspace, $repository->slug, $token)
            : new BitbucketClient($repository->workspace, $repository->slug, $token);

        // What they write passes the tenant's secrets first, as the review's does.
        return ['client' => $client->keepingOut(SecretFilter::forTenant($repository->tenant_id)), 'account_id' => $connection->account_id];
    }

    /**
     * An access token that works now: renewed when it ran out, which Bitbucket's
     * do after two hours and the GitHub App's after eight. Null when the host
     * refuses to renew it.
     */
    protected function accessToken(?PersonalConnection $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        if (! $connection->isExpired()) {
            return $connection->access_token;
        }

        if ($connection->refresh_token === null) {
            return null;
        }

        try {
            $result = $this->renew($connection->host, $connection->refresh_token);
        } catch (Throwable $exception) {
            report($exception);

            $result = [];
        }

        if (! is_string($result['access_token'] ?? null)) {
            // GitHub takes a refresh token once: another request may have renewed it first.
            $current = $connection->fresh();

            return $current !== null && $current->refresh_token !== $connection->refresh_token && ! $current->isExpired() ? $current->access_token : null;
        }

        // Kept only while the row is still the account that was renewed: one the
        // user connected meanwhile stays, and is what is used.
        return DB::transaction(function () use ($connection, $result): ?string {
            $current = PersonalConnection::query()->whereKey($connection->getKey())->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }

            if ($current->refresh_token !== $connection->refresh_token || $current->account_id !== $connection->account_id) {
                return $current->isExpired() ? null : $current->access_token;
            }

            $current->forceFill([
                'access_token' => $result['access_token'],
                'refresh_token' => is_string($result['refresh_token'] ?? null) ? $result['refresh_token'] : $current->refresh_token,
                'expires_at' => now()->addSeconds((int) ($result['expires_in'] ?? 7200)),
            ])->save();

            return $current->access_token;
        });
    }

    /**
     * What the host answers to a refresh token: a new access token, and on GitHub
     * a new refresh token too, as the old one is then spent. GitHub answers a
     * refusal with 200 and an error, so the absence of a token is the refusal.
     *
     * @return array<string, mixed>
     */
    protected function renew(string $host, string $refreshToken): array
    {
        if ($host === self::GITHUB) {
            return (array) Http::asForm()->acceptJson()->timeout(10)
                ->post('https://github.com/login/oauth/access_token', [
                    'client_id' => (string) config('services.github_app.client_id'),
                    'client_secret' => (string) config('services.github_app.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ])
                ->throw()
                ->json();
        }

        return (array) Http::asForm()
            ->withBasicAuth((string) config('services.bitbucket.client_id'), (string) config('services.bitbucket.client_secret'))
            ->timeout(10)
            ->post('https://bitbucket.org/site/oauth2/access_token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])
            ->throw()
            ->json();
    }
}
