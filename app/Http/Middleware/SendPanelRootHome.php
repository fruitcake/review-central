<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SendPanelRootHome
{
    /**
     * The tenant panel's own address, without a tenant, answers 404 for a user who
     * has none and picks a tenant for an administrator. Home decides for both.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $request->routeIs('filament.app.tenant') ? redirect()->route('home') : $next($request);
    }
}
