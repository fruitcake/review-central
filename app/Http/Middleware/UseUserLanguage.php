<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseUserLanguage
{
    /**
     * The panels in the language the user chose on their profile: Filament's own
     * words, dates and validation messages, and what is translated of ours.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->language !== null) {
            app()->setLocale($user->language->value);
        }

        return $next($request);
    }
}
