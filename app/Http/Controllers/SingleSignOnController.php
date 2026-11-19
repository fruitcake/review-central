<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\SignInRefused;
use App\Services\SingleSignOn;
use Filament\Facades\Filament;
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
    public function redirect(string $provider): SymfonyRedirect
    {
        return $this->driver($provider)->redirect();
    }

    /**
     * Back from the provider: signed in here as the user that account is, or
     * told on the sign-in page why not.
     */
    public function callback(Request $request, string $provider, SingleSignOn $signOn): RedirectResponse
    {
        $login = (string) Filament::getPanel('app')->getLoginUrl();

        try {
            $account = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            // Cancelled there, or a link that was opened twice: nothing to report.
            return redirect()->to($login)->with('sign_in_refused', 'Signing in did not complete. Try again.');
        }

        try {
            $user = $signOn->user($provider, $account);
        } catch (SignInRefused $exception) {
            return redirect()->to($login)->with('sign_in_refused', $exception->getMessage());
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    protected function driver(string $provider): AbstractProvider
    {
        abort_unless(array_key_exists($provider, SingleSignOn::providers()), 404);

        $driver = Socialite::driver($provider);

        abort_unless($driver instanceof AbstractProvider, 404);

        return $driver->redirectUrl(route('sign-in.callback', ['provider' => $provider]));
    }
}
