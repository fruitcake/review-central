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
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Connects a user's own Bitbucket account on their profile, so that what they
 * write on a pull request through Review Central is written as them.
 */
class PersonalConnectionController extends Controller
{
    /** In the session while a sign-in with Bitbucket is under way. */
    public const SIGN_IN = 'bitbucket_sign_in';

    /**
     * Off to Bitbucket, to say yes there.
     */
    public function redirect(Request $request): SymfonyRedirect
    {
        abort_unless(PersonalConnections::available(), 404);

        // Back to the profile it was started from, in the tenant it was open in
        // where the user is in that one.
        $tenant = Tenant::query()->where('slug', (string) $request->query('tenant'))->first();
        $user = $request->user();
        $request->session()->put('personal_connection.return', $tenant !== null && $user instanceof User && $user->canAccessTenant($tenant)
            ? EditProfile::getUrl(panel: 'app', tenant: $tenant)
            : EditProfile::urlForCurrentUser());
        $request->session()->forget(self::SIGN_IN);

        return $this->driver()->setScopes(PersonalConnections::BITBUCKET_SCOPES)->redirect();
    }

    /**
     * Back from Bitbucket: a sign-in goes on to signing in, else the account is
     * kept for the user who asked, and signs them in from now on.
     */
    public function callback(Request $request, PersonalConnections $connections, SingleSignOn $signOn): RedirectResponse
    {
        abort_unless(PersonalConnections::available(), 404);

        // The consumer has one callback: a sign-in started there ends on its own route.
        if ($request->session()->pull(self::SIGN_IN) === true) {
            return redirect()->to(route('sign-in.callback', ['provider' => SingleSignOn::BITBUCKET]).'?'.http_build_query($request->query()));
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('filament.app.auth.login'));
        }

        $return = (string) ($request->session()->pull('personal_connection.return') ?? EditProfile::urlForCurrentUser());

        try {
            /** @var \Laravel\Socialite\Two\User $account */
            $account = $this->driver()->user();
        } catch (Throwable) {
            // Cancelled there, or a link that was opened twice: nothing to report.
            Notification::make()->title('Bitbucket was not connected')->body('Connecting did not complete. Try again.')->danger()->send();

            return redirect()->to($return);
        }

        try {
            $signOn->link($user, SingleSignOn::BITBUCKET, (string) $account->getId());
        } catch (SignInRefused $exception) {
            Notification::make()->title('Bitbucket was not connected')->body($exception->getMessage())->danger()->send();

            return redirect()->to($return);
        }

        $connection = $connections->connect($user, $account, $account->token, $account->refreshToken, $account->expiresIn === null ? null : (int) $account->expiresIn, $connections->grantedScopes($account->token));

        Notification::make()->title('Bitbucket is connected')->body('As '.($connection->nickname ?? $account->getName() ?? 'your account').': what you write on a pull request through Review Central is now written as you.')->success()->send();

        return redirect()->to($return);
    }

    protected function driver(): AbstractProvider
    {
        $driver = Socialite::driver('bitbucket');

        abort_unless($driver instanceof AbstractProvider, 404);

        return $driver->redirectUrl(route('connections.bitbucket.callback'));
    }
}
