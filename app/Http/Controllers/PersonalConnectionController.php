<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\SignInRefused;
use App\Filament\Auth\EditProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PersonalConnections;
use App\Services\SingleSignOn;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Connects a user's own Bitbucket or GitHub account on their profile, so that
 * what they write on a pull request through Review Central is written as them.
 */
class PersonalConnectionController extends Controller
{
    /** In the session while a sign-in is under way: the host it is at. */
    public const SIGN_IN = 'personal_sign_in';

    /**
     * Off to the host, to say yes there.
     */
    public function redirect(Request $request, string $host): SymfonyRedirect
    {
        $provider = $this->provider($host);

        // Back to the profile it was started from, in the tenant it was open in
        // where the user is in that one.
        $tenant = Tenant::query()->where('slug', (string) $request->query('tenant'))->first();
        $user = $request->user();
        $request->session()->put('personal_connection.return', $tenant !== null && $user instanceof User && $user->canAccessTenant($tenant)
            ? EditProfile::getUrl(panel: 'app', tenant: $tenant)
            : EditProfile::urlForCurrentUser());
        $request->session()->forget(self::SIGN_IN);

        return $provider->redirect();
    }

    /**
     * Back from the host: a sign-in goes on to signing in, else the account is
     * kept for the user who asked, and signs them in from now on.
     */
    public function callback(Request $request, string $host, PersonalConnections $connections, SingleSignOn $signOn): RedirectResponse
    {
        $provider = $this->provider($host);
        $name = PersonalConnections::HOSTS[$host];

        // The app has one callback for both: a sign-in started there ends on its own route.
        if ($request->session()->pull(self::SIGN_IN) === $host) {
            return redirect()->to(route('sign-in.callback', ['provider' => $host]).'?'.http_build_query($request->query()));
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('filament.app.auth.login'));
        }

        $return = (string) ($request->session()->pull('personal_connection.return') ?? EditProfile::urlForCurrentUser());

        try {
            /** @var \Laravel\Socialite\Two\User $account */
            $account = $provider->user();
        } catch (Throwable) {
            // Cancelled there, or a link that was opened twice: nothing to report.
            Notification::make()->title("{$name} was not connected")->body('Connecting did not complete. Try again.')->danger()->send();

            return redirect()->to($return);
        }

        try {
            $signOn->link($user, $host, (string) $account->getId());
        } catch (SignInRefused $exception) {
            Notification::make()->title("{$name} was not connected")->body($exception->getMessage())->danger()->send();

            return redirect()->to($return);
        }

        $connection = $connections->connect(
            $user,
            $host,
            $account,
            $account->token,
            $account->refreshToken,
            $account->expiresIn === null ? null : (int) $account->expiresIn,
            $host === PersonalConnections::BITBUCKET ? $connections->grantedScopes($account->token) : [],
        );

        Notification::make()->title("{$name} is connected")->body('As '.($connection->nickname ?? $account->getName() ?? 'your account').': what you write on a pull request through Review Central is now written as you.')->success()->send();

        return redirect()->to($return);
    }

    protected function provider(string $host): AbstractProvider
    {
        $provider = PersonalConnections::provider($host);

        abort_if($provider === null, 404);

        return $provider;
    }
}
