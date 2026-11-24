<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\SignInRefused;
use App\Models\User;
use App\Services\PersonalConnections;
use App\Services\SignInCodes;
use App\Services\SingleSignOn;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class SingleSignOnController extends Controller
{
    /** In the session while a code is out: the account's token, encrypted, to connect it once the code links it. */
    public const PENDING_CONNECTION = 'sign_in_connection';

    /**
     * Off to the provider, to sign in there.
     */
    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $driver = $this->driver($provider);

        if (in_array($provider, SingleSignOn::BY_CODE, true)) {
            // Back through the one callback the consumer or app has for people,
            // which sends a sign-in on here.
            $request->session()->put(PersonalConnectionController::SIGN_IN, $provider);
        }

        return $driver->redirect();
    }

    /**
     * Back from the provider: signed in here as the user that account is, or
     * told on the sign-in page why not.
     */
    public function callback(Request $request, string $provider, SingleSignOn $signOn, SignInCodes $codes, PersonalConnections $connections): RedirectResponse
    {
        $login = (string) Filament::getPanel('app')->getLoginUrl();

        try {
            $account = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            // Cancelled there, or a link that was opened twice: nothing to report.
            return redirect()->to($login)->with('sign_in_refused', 'Signing in did not complete. Try again.');
        }

        try {
            if (in_array($provider, SingleSignOn::BY_CODE, true)) {
                $user = $signOn->known($provider, (string) $account->getId());

                if ($user === null) {
                    $token = $account instanceof SocialiteUser ? (string) $account->token : '';
                    $codes->start($request->session(), $provider, $account, $connections->verifiedEmails($provider, $token, $account->getEmail()));

                    // Connected as well once the code links it, without asking the host again.
                    if ($account instanceof SocialiteUser) {
                        $request->session()->put(self::PENDING_CONNECTION, Crypt::encrypt([
                            'provider' => $provider,
                            'raw' => $account->getRaw(),
                            'id' => (string) $account->getId(),
                            'nickname' => $account->getNickname(),
                            'name' => $account->getName(),
                            'token' => $account->token,
                            'refresh_token' => $account->refreshToken,
                            // When it runs out, not how long it lasts: the code may be typed minutes later.
                            'expires_at' => $account->expiresIn === null ? null : now()->addSeconds((int) $account->expiresIn)->getTimestamp(),
                        ]));
                    }

                    return redirect()->route('sign-in.code');
                }

                if ($account instanceof SocialiteUser) {
                    $this->connect($connections, $user, $provider, $account);
                }
            } else {
                $user = $signOn->user($provider, $account);
            }
        } catch (SignInRefused $exception) {
            return redirect()->to($login)->with('sign_in_refused', $exception->getMessage());
        }

        return $this->signIn($request, $user);
    }

    /**
     * The page that asks for the code mailed to the address.
     */
    public function code(Request $request, SignInCodes $codes): View|RedirectResponse
    {
        $email = $codes->pendingEmail($request->session());

        if ($email === null) {
            return redirect()->to((string) Filament::getPanel('app')->getLoginUrl())->with('sign_in_refused', 'That code is no longer valid. Sign in again for a new one.');
        }

        $provider = (string) $codes->pendingProvider($request->session());

        return view('sign-in-code', ['email' => $email, 'minutes' => SignInCodes::MINUTES, 'provider' => $provider, 'name' => SingleSignOn::providers()[$provider] ?? $provider]);
    }

    /**
     * The code typed in: signed in, or told why not.
     */
    public function checkCode(Request $request, SignInCodes $codes, SingleSignOn $signOn, PersonalConnections $connections): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $user = $codes->finish($request->session(), (string) $request->input('code'));
        } catch (SignInRefused $exception) {
            if ($codes->pendingEmail($request->session()) === null) {
                $request->session()->forget(self::PENDING_CONNECTION);

                return redirect()->to((string) Filament::getPanel('app')->getLoginUrl())->with('sign_in_refused', $exception->getMessage());
            }

            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        $pending = rescue(fn (): mixed => Crypt::decrypt((string) $request->session()->pull(self::PENDING_CONNECTION)), null, false);

        // Only the account the code just linked to this user.
        if (is_array($pending) && $signOn->known($pending['provider'], $pending['id'])?->is($user) === true) {
            $account = (new SocialiteUser)->setRaw($pending['raw'])
                ->map(['id' => $pending['id'], 'nickname' => $pending['nickname'], 'name' => $pending['name']])
                ->setToken($pending['token'])
                ->setRefreshToken($pending['refresh_token'])
                ->setExpiresIn($pending['expires_at'] === null ? null : max(0, $pending['expires_at'] - now()->getTimestamp()));
            $this->connect($connections, $user, $pending['provider'], $account);
        }

        return $this->signIn($request, $user);
    }

    /**
     * Keeps the token the sign-in brought, so the account writes as the user as
     * well, as connecting it on the profile would. A failure there leaves the
     * sign-in as it is.
     */
    protected function connect(PersonalConnections $connections, User $user, string $provider, SocialiteUser $account): void
    {
        if (blank($account->token)) {
            return;
        }

        rescue(fn () => $connections->connect(
            $user,
            $provider,
            $account,
            $account->token,
            $account->refreshToken,
            $account->expiresIn === null ? null : (int) $account->expiresIn,
            $provider === PersonalConnections::BITBUCKET ? $connections->grantedScopes($account->token) : [],
        ));
    }

    protected function signIn(Request $request, User $user): RedirectResponse
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    protected function driver(string $provider): AbstractProvider
    {
        abort_unless(array_key_exists($provider, SingleSignOn::providers()), 404);

        // Bitbucket's consumer and the GitHub App have one callback for people,
        // which connecting an account uses too.
        $driver = in_array($provider, SingleSignOn::BY_CODE, true) ? PersonalConnections::provider($provider) : Socialite::driver($provider);

        abort_unless($driver instanceof AbstractProvider, 404);

        return in_array($provider, SingleSignOn::BY_CODE, true) ? $driver : $driver->redirectUrl(route('sign-in.callback', ['provider' => $provider]));
    }
}
