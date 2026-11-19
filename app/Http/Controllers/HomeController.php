<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Landing;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Sends everyone to where they belong, and tells a user without a tenant why
     * there is nothing to show yet.
     */
    public function __invoke(Request $request, Landing $landing): RedirectResponse|View
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->to((string) Filament::getPanel('app')->getLoginUrl());
        }

        $url = $landing->urlFor($user);

        return $url === null
            ? view('no-tenant', ['user' => $user, 'logout' => Filament::getPanel('app')->getLogoutUrl()])
            : redirect()->to($url);
    }
}
