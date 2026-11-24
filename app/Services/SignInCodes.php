<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SignInRefused;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;

/**
 * Signing in with an account at a provider that names an address but does not
 * deliver its mail, as Bitbucket and GitHub: someone who had the address at a former
 * employer may still have an account there under it. The first time, a code is
 * mailed to the address and has to be typed in the browser that signed in, which
 * shows the address is theirs now; the account is linked, and signs in by
 * itself after that.
 *
 * A code rather than a link: whoever started the sign-in has to type it, so the
 * owner of the address who clicks a mail they did not expect links nothing.
 */
class SignInCodes
{
    public const MINUTES = 15;

    public const ATTEMPTS = 5;

    protected const SESSION = 'sign_in_code';

    public function __construct(protected SingleSignOn $signOn) {}

    /**
     * Mails a code to the one of the account's addresses it would sign in
     * with, and keeps what it is for in the session. Of the account's confirmed
     * addresses, the first that has an account here; else the first a tenant
     * lets in, for a new one.
     *
     * @param  array<int, string>  $emails  the account's confirmed addresses, the primary one first
     *
     * @throws SignInRefused when the account has no confirmed address, or none would sign in or join a tenant
     */
    public function start(Session $session, string $provider, ProviderUser $account, array $emails): string
    {
        $emails = array_values(array_unique(array_filter(array_map(fn (string $email): string => Str::lower(trim($email)), $emails))));
        $id = (string) $account->getId();

        if ($id === '' || $emails === []) {
            throw new SignInRefused('Your account there has no confirmed email address, so it cannot be used to sign in here.');
        }

        $email = $this->addressFor($provider, $emails);
        $code = (string) random_int(100000, 999999);

        $session->put(self::SESSION, [
            'provider' => $provider,
            'id' => $id,
            'email' => $email,
            'name' => trim((string) $account->getName()) ?: Str::before($email, '@'),
            'hash' => $this->hash($code),
            'expires' => now()->addMinutes(self::MINUTES)->getTimestamp(),
            'attempts' => 0,
        ]);

        Notification::route('mail', $email)->notify(new SignInCode($code, $account->getNickname() ?? $account->getName() ?? $email, SingleSignOn::providers()[$provider] ?? $provider));

        return $email;
    }

    /**
     * The address to send the code to.
     *
     * @param  array<int, string>  $emails
     *
     * @throws SignInRefused when none would sign in or join a tenant
     */
    protected function addressFor(string $provider, array $emails): string
    {
        $linked = null;

        foreach ($emails as $email) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($user === null) {
                continue;
            }

            // Its account has another one there: that one signs it in, not this.
            if ($user->identities()->where('provider', $provider)->exists()) {
                $linked ??= $email;

                continue;
            }

            return $email;
        }

        foreach ($emails as $email) {
            if ($this->signOn->tenantsForDomain(Str::after($email, '@'))->isNotEmpty()) {
                return $email;
            }
        }

        if ($linked !== null) {
            throw new SignInRefused("The account for {$linked} is already linked to another account there. Sign in with that one, or with your password.");
        }

        throw new SignInRefused('There is no account for '.implode(' or ', $emails).'. Ask for an invitation, or ask your organisation\'s administrator to let its people sign in.');
    }

    /**
     * The address a code was sent to and not yet used, for the page that asks for it.
     */
    public function pendingEmail(Session $session): ?string
    {
        $pending = $session->get(self::SESSION);

        return is_array($pending) && $pending['expires'] > now()->getTimestamp() ? $pending['email'] : null;
    }

    /**
     * The provider of the account a code was sent for and not yet used.
     */
    public function pendingProvider(Session $session): ?string
    {
        return $this->pendingEmail($session) === null ? null : $session->get(self::SESSION.'.provider');
    }

    /**
     * The user the code signs in: the address's own, or one made for it where a
     * tenant lets its domain in, with the account linked to it either way.
     *
     * @throws SignInRefused when the code is wrong, ran out, or was tried too often
     */
    public function finish(Session $session, string $code): User
    {
        $pending = $session->get(self::SESSION);

        if (! is_array($pending) || $pending['expires'] <= now()->getTimestamp() || $pending['attempts'] >= self::ATTEMPTS) {
            $session->forget(self::SESSION);

            throw new SignInRefused('That code is no longer valid. Sign in again for a new one.');
        }

        if (! hash_equals($pending['hash'], $this->hash(trim($code)))) {
            $session->put(self::SESSION.'.attempts', $pending['attempts'] + 1);

            throw new SignInRefused('That is not the code we sent.');
        }

        $session->forget(self::SESSION);

        return DB::transaction(function () use ($pending): User {
            $user = User::query()->whereRaw('lower(email) = ?', [$pending['email']])->first();
            $tenants = $this->signOn->tenantsForDomain(Str::after($pending['email'], '@'));

            if ($user === null) {
                // Checked when the code was sent, and again now: a domain may have been taken off since.
                if ($tenants->isEmpty()) {
                    throw new SignInRefused("There is no account for {$pending['email']}. Ask for an invitation, or ask your organisation's administrator to let its people sign in.");
                }

                $user = User::query()->create([
                    'name' => $pending['name'],
                    'email' => $pending['email'],
                    // Nobody knows it: a password is chosen through "Forgot password", if ever.
                    'password' => Str::random(64),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->signOn->link($user, $pending['provider'], $pending['id'], replace: false);
            $user->tenants()->syncWithoutDetaching($tenants->modelKeys());

            return $user;
        });
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
