<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalConnection;
use App\Models\Repository;
use App\Models\User;
use App\SourceControl\BitbucketClient;
use App\SourceControl\ReadOnlyClient;
use App\SourceControl\SourceControl;
use App\Support\SecretFilter;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Throwable;

/**
 * A user's own Bitbucket account, connected on their profile, so that what they
 * write on a pull request through Review Central is written as them rather than
 * as the tenant's connection. Kept apart from the connections, which the reviews
 * use: this one is only ever used for its user, and only to write what they wrote.
 */
class PersonalConnections
{
    public const BITBUCKET = 'bitbucket';

    /**
     * Who the account is, and commenting on pull requests, which `pullrequest`
     * allows without merging or writing to the repository. Bitbucket gives a
     * token the permissions of its consumer whatever is asked, so the consumer
     * itself should have no more.
     *
     * @var array<int, string>
     */
    public const BITBUCKET_SCOPES = ['account', 'pullrequest'];

    /**
     * Scopes that include one of BITBUCKET_SCOPES, as Bitbucket names a token's.
     *
     * @var array<string, string>
     */
    protected const IMPLIED = ['pullrequest:write' => 'pullrequest', 'account:write' => 'account'];

    /**
     * Whether this installation is registered at Bitbucket, so users can connect.
     */
    public static function available(): bool
    {
        return filled(config('services.bitbucket.client_id')) && filled(config('services.bitbucket.client_secret'));
    }

    public function of(User $user): ?PersonalConnection
    {
        return PersonalConnection::query()->where('user_id', $user->getKey())->where('host', self::BITBUCKET)->first();
    }

    /**
     * Keeps the account the user signed in as at Bitbucket, in place of any they
     * had, and names it among their accounts on the hosts, which tells whose
     * turn a pull request is.
     *
     * @param  array<int, string>  $scopes  what Bitbucket granted
     */
    public function connect(User $user, ProviderUser $account, string $accessToken, ?string $refreshToken, ?int $expiresIn, array $scopes): PersonalConnection
    {
        $raw = method_exists($account, 'getRaw') ? (array) $account->getRaw() : [];
        $accountId = is_string($raw['account_id'] ?? null) && $raw['account_id'] !== '' ? $raw['account_id'] : (string) $account->getId();
        $nickname = $account->getNickname() ?? (is_string($raw['nickname'] ?? null) ? $raw['nickname'] : null);

        $connection = PersonalConnection::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'host' => self::BITBUCKET],
            [
                'account_id' => $accountId,
                'nickname' => $nickname,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresIn === null ? null : now()->addSeconds($expiresIn),
                'scopes' => array_values(array_filter($scopes)),
            ],
        );

        $user->forceFill(['source_accounts' => array_values(array_unique([...$user->source_accounts ?? [], ...array_filter([$accountId, $nickname])]))])->save();

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

    public function disconnect(User $user): void
    {
        PersonalConnection::query()->where('user_id', $user->getKey())->where('host', self::BITBUCKET)->delete();
    }

    /**
     * Why the user's account cannot write right now, in a sentence, or null when
     * it can: none connected, a token Bitbucket no longer renews, or one granted
     * before writing on pull requests was asked for.
     */
    public function problem(User $user): ?string
    {
        $connection = $this->of($user);

        return match (true) {
            $connection === null => 'Your Bitbucket account is not connected.',
            ! $this->canComment($connection->scopes ?? []) => 'Your Bitbucket account was connected without the right to comment on pull requests: connect it again on your profile.',
            $this->accessToken($connection) === null => 'Bitbucket no longer accepts your connection: connect your account again on your profile.',
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
     * their own account cannot: a repository on another host, no account or one
     * that cannot write, or a connection set to write nothing.
     */
    public function writerFor(User $user, Repository $repository): ?SourceControl
    {
        if ($repository->integration?->driver !== 'bitbucket' || $this->problem($user) !== null) {
            return null;
        }

        if ($repository->sourceControl() instanceof ReadOnlyClient) {
            return null;
        }

        // What they write passes the tenant's secrets first, as the review's does.
        return (new BitbucketClient($repository->workspace, $repository->slug, (string) $this->accessToken($this->of($user))))
            ->keepingOut(SecretFilter::forTenant($repository->tenant_id));
    }

    /**
     * An access token that works now: renewed when it ran out, which Bitbucket's
     * do after two hours. Null when Bitbucket refuses to renew it.
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
            $result = Http::asForm()
                ->withBasicAuth((string) config('services.bitbucket.client_id'), (string) config('services.bitbucket.client_secret'))
                ->timeout(10)
                ->post('https://bitbucket.org/site/oauth2/access_token', ['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_string($result['access_token'] ?? null)) {
            return null;
        }

        $connection->forceFill([
            'access_token' => $result['access_token'],
            'refresh_token' => is_string($result['refresh_token'] ?? null) ? $result['refresh_token'] : $connection->refresh_token,
            'expires_at' => now()->addSeconds((int) ($result['expires_in'] ?? 7200)),
        ])->save();

        return $connection->access_token;
    }
}
