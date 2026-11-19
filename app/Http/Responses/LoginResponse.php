<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements Responsable
{
    /**
     * Back to the page that asked for the sign-in, and otherwise home, which knows
     * where this user belongs. Filament's own answer is the panel, which has no
     * page for a user without a tenant.
     */
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->intended(route('home'));
    }
}
