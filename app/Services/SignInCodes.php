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
 * deliver its mail, as Bitbucket: someone who had the address at a former
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
     * Mails a code to the account's address, and keeps what it is for in the
     * session.
     *
     * @throws SignInRefused when the account has no confirmed address, or the address would neither sign in nor join a tenant
     */
    public function start(Session $session, string $provider, ProviderUser $account): string
    {
        $email = Str::lower(trim((string) $account->getEmail()));
        $id = (string) $account->getId();

        if ($id === '' || $email === '') {
            throw new SignInRefused('Your account there has no confirmed primary email address, so it cannot be used to sign in here.');
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null && $this->signOn->tenantsForDomain(Str::after($email, '@'))->isEmpty()) {
            throw new SignInRefused("There is no account for {$email}. Ask for an invitation, or ask your organisation's administrator to let its people sign in.");
        }

        if ($user !== null && $user->identities()->where('provider', $provider)->exists()) {
            throw new SignInRefused("The account for {$email} is already linked to another account there. Sign in with that one, or with your password.");
        }

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

        Notification::route('mail', $email)->notify(new SignInCode($code, $account->getNickname() ?? $account->getName() ?? $email));

        return $email;
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
