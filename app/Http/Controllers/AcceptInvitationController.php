<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AcceptInvitationController extends Controller
{
    /**
     * Trades the link from the invitation for the page where a password is chosen:
     * the admin panel's for an administrator, the tenant panel's for anyone else.
     *
     * An invitation whose password was chosen already goes to the sign-in page.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $panel = $user->panel();

        if (! hash_equals(Invitation::state($user), (string) $request->query('state'))) {
            return redirect()->to((string) $panel->getLoginUrl());
        }

        $token = Password::broker($panel->getAuthPasswordBroker())->createToken($user);

        return redirect()->to($panel->getResetPasswordUrl($token, $user));
    }
}
