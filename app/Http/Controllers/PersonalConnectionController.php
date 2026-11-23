<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Auth\EditProfile;
use App\Models\User;
use App\Services\PersonalConnections;
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
    /**
     * Off to Bitbucket, to say yes there.
     */
    public function redirect(Request $request): SymfonyRedirect
    {
        abort_unless(PersonalConnections::available(), 404);

        // Back to the profile it was started from, in the tenant that was open.
        $request->session()->put('personal_connection.return', EditProfile::urlForCurrentUser());

        return $this->driver()->setScopes(PersonalConnections::BITBUCKET_SCOPES)->redirect();
    }

    /**
     * Back from Bitbucket: the account is kept for the user who asked.
     */
    public function callback(Request $request, PersonalConnections $connections): RedirectResponse
    {
        abort_unless(PersonalConnections::available(), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $return = (string) ($request->session()->pull('personal_connection.return') ?? EditProfile::urlForCurrentUser());

        try {
            /** @var \Laravel\Socialite\Two\User $account */
            $account = $this->driver()->user();
        } catch (Throwable) {
            // Cancelled there, or a link that was opened twice: nothing to report.
            Notification::make()->title('Bitbucket was not connected')->body('Connecting did not complete. Try again.')->danger()->send();

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
