<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SignInRefused;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;

/**
 * Signing in with an account somewhere else, such as Google, instead of a password.
 *
 * Someone who has an account here signs in to it when the provider vouches for
 * their address. Someone who has none gets one only where a tenant lets their
 * organisation in: a tenant lists the domains whose people may join it without an
 * invitation, and the provider has to say the account is that organisation's own.
 */
class SingleSignOn
{
    public const GOOGLE = 'google';

    /**
     * Bitbucket and GitHub say which address an account has, not that they
     * deliver that address's mail: an account they do not know yet signs in
     * only once its address has answered a code ({@see SignInCodes}).
     */
    public const BITBUCKET = PersonalConnections::BITBUCKET;

    public const GITHUB = PersonalConnections::GITHUB;

    /**
     * The providers whose accounts are linked by a mailed code the first time.
     *
     * @var array<int, string>
     */
    public const BY_CODE = [self::BITBUCKET, self::GITHUB];

    /**
     * The providers this installation is set up for, with the name on the button.
     *
     * @return array<string, string>
     */
    public static function providers(): array
    {
        return array_filter([
            self::GOOGLE => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')) ? 'Google' : null,
            self::BITBUCKET => PersonalConnections::available(self::BITBUCKET) ? 'Bitbucket' : null,
            self::GITHUB => PersonalConnections::available(self::GITHUB) ? 'GitHub' : null,
        ]);
    }

    /**
     * The user an account at the provider was linked to, if any.
     */
    public function known(string $provider, string $id): ?User
    {
        return UserIdentity::query()->where('provider', $provider)->where('provider_id', $id)->first()?->user;
    }

    /**
     * Links an account at the provider to a user, so it signs them in from now on.
     * Only a user who is signed in replaces one they linked before; signing in
     * with another account never does.
     *
     * @throws SignInRefused when the account is another user's, or the user has another one linked and it is not to be replaced
     */
    public function link(User $user, string $provider, string $id, bool $replace = true): void
    {
        $other = $this->known($provider, $id);

        if ($other !== null && ! $other->is($user)) {
            throw new SignInRefused('That account is already linked to another account here.');
        }

        if ($replace) {
            UserIdentity::query()->updateOrCreate(['user_id' => $user->getKey(), 'provider' => $provider], ['provider_id' => $id]);

            return;
        }

        try {
            // One per user and provider: an account linked meanwhile makes this fail rather than be replaced.
            UserIdentity::query()->firstOrCreate(['user_id' => $user->getKey(), 'provider' => $provider, 'provider_id' => $id]);
        } catch (UniqueConstraintViolationException) {
            throw new SignInRefused("The account for {$user->email} is already linked to another account there. Sign in with that one, or with your password.");
        }
    }

    /**
     * The tenants that let in the people of a domain without an invitation.
     *
     * @return Collection<int, Tenant>
     */
    public function tenantsForDomain(string $domain): Collection
    {
        return Tenant::query()->whereJsonContains('sign_in_domains', Str::lower($domain))->get();
    }

    /**
     * The user this account signs in as, made when a tenant takes their organisation.
     *
     * @throws SignInRefused with why not, in words for the person
     */
    public function user(string $provider, ProviderUser $account): User
    {
        $raw = method_exists($account, 'getRaw') ? (array) $account->getRaw() : [];
        $email = Str::lower(trim((string) $account->getEmail()));
        $id = (string) $account->getId();

        // An address the provider has not checked is anybody's to claim.
        if ($id === '' || $email === '' || ! $this->verified($provider, $raw)) {
            throw new SignInRefused('Your account there has no verified email address, so it cannot be used to sign in here.');
        }

        $known = $this->known($provider, $id);

        if ($known !== null) {
            $this->join($known, $provider, $email, $raw);

            return $known;
        }

        return DB::transaction(function () use ($provider, $id, $email, $account, $raw): User {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                if ($this->tenantsFor($provider, $email, $raw)->isEmpty()) {
                    throw new SignInRefused("There is no account for {$email}. Ask for an invitation, or ask your organisation's administrator to let its people sign in.");
                }

                $user = User::query()->create([
                    'name' => trim((string) $account->getName()) ?: Str::before($email, '@'),
                    'email' => $email,
                    // Nobody knows it: a password is chosen through "Forgot password", if ever.
                    'password' => Str::random(64),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            } elseif (! $this->holdsAddress($provider, $email, $raw)) {
                // "Verified" was true once, when the account there was made. Only
                // for an address the provider itself delivers is it true today:
                // someone who had this address at a former employer may still
                // have a personal account under it.
                throw new SignInRefused("That account cannot vouch for {$email}: the address is not one its provider manages. Sign in with your password.");
            } elseif ($user->identities()->where('provider', $provider)->exists()) {
                throw new SignInRefused("The account for {$email} is already linked to another account there. Sign in with that one, or with your password.");
            }

            UserIdentity::query()->create(['user_id' => $user->getKey(), 'provider' => $provider, 'provider_id' => $id]);
            $this->join($user, $provider, $email, $raw);

            return $user;
        });
    }

    /**
     * Adds someone to the tenants that take their organisation, also when they
     * had an account before the tenant listed its domain. Never out of one.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function join(User $user, string $provider, string $email, array $raw): void
    {
        $user->tenants()->syncWithoutDetaching($this->tenantsFor($provider, $email, $raw)->modelKeys());
    }

    /**
     * Whether the provider delivers this address's mail itself, so that its word
     * for who holds the address is good now and not only when the account was made.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function holdsAddress(string $provider, string $email, array $raw): bool
    {
        $domain = Str::after($email, '@');

        return match ($provider) {
            self::GOOGLE => in_array($domain, ['gmail.com', 'googlemail.com'], true) || $this->organisation($provider, $raw) === $domain,
            default => false,
        };
    }

    /**
     * The organisation that manages the account, as the provider names it: Google
     * gives `hd` only for an account its organisation manages.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function organisation(string $provider, array $raw): ?string
    {
        return match ($provider) {
            self::GOOGLE => is_string($raw['hd'] ?? null) && $raw['hd'] !== '' ? Str::lower($raw['hd']) : null,
            default => null,
        };
    }

    /**
     * The tenants that let this account's organisation in.
     *
     * The organisation is what the provider says it is, not what the address ends
     * in: anyone can make a personal account under an address at a domain.
     *
     * @param  array<string, mixed>  $raw
     * @return Collection<int, Tenant>
     */
    protected function tenantsFor(string $provider, string $email, array $raw): Collection
    {
        $domain = $this->organisation($provider, $raw);

        if ($domain === null || Str::after($email, '@') !== $domain) {
            return Tenant::query()->whereRaw('1 = 0')->get();
        }

        return $this->tenantsForDomain($domain);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function verified(string $provider, array $raw): bool
    {
        return match ($provider) {
            self::GOOGLE => ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true,
            default => false,
        };
    }
}
