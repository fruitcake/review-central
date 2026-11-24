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
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class SingleSignOnController extends Controller
{
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
    public function callback(Request $request, string $provider, SingleSignOn $signOn, SignInCodes $codes): RedirectResponse
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
                    $codes->start($request->session(), $provider, $account);

                    return redirect()->route('sign-in.code');
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
    public function checkCode(Request $request, SignInCodes $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $user = $codes->finish($request->session(), (string) $request->input('code'));
        } catch (SignInRefused $exception) {
            return $codes->pendingEmail($request->session()) === null
                ? redirect()->to((string) Filament::getPanel('app')->getLoginUrl())->with('sign_in_refused', $exception->getMessage())
                : back()->withErrors(['code' => $exception->getMessage()]);
        }

        return $this->signIn($request, $user);
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
