<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Connections\ConnectsWithOAuth;
use App\Connections\Integrations;
use App\Connections\OAuthTokens;
use App\Enums\ConnectionKind;
use App\Exceptions\ConnectionFailed;
use App\Filament\App\Resources\AiProviders\AiProviderResource;
use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Filament\App\Resources\SourceControl\SourceControlResource;
use App\Filament\App\Resources\Tickets\TicketResource;
use App\Models\Connection;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gives a connection its access by signing in at the service, for a service this
 * installation is registered with as an app.
 */
class ConnectionOAuthController extends Controller
{
    /**
     * Off to the service, to say yes there.
     */
    public function redirect(Request $request, Connection $connection): RedirectResponse
    {
        $driver = $this->driver($request, $connection);
        $state = Str::random(40);

        // Which connection the answer is for is kept here, not sent along: the
        // answer comes back through the person's browser.
        $request->session()->put('connection_oauth', ['connection' => $connection->getKey(), 'state' => $state]);

        return redirect()->away($driver->oauthAuthorizeUrl(route('connections.oauth.callback'), $state));
    }

    /**
     * Back from the service with a code, which is traded for the connection's tokens.
     */
    public function callback(Request $request, OAuthTokens $tokens): RedirectResponse
    {
        $pending = $request->session()->pull('connection_oauth');
        $connection = is_array($pending) && is_int($pending['connection'] ?? null) ? Connection::query()->whereKey($pending['connection'])->first() : null;

        if ($connection === null) {
            abort(404);
        }

        $driver = $this->driver($request, $connection);
        // Back to where the connection is listed: a ticket service on Tickets.
        $back = match (true) {
            $connection->kind === ConnectionKind::Tickets && $connection->ticketSources()->exists() => TicketResource::getUrl('index', tenant: $connection->tenant),
            $connection->kind === ConnectionKind::SourceControl => SourceControlResource::getUrl('index', tenant: $connection->tenant),
            $connection->kind === ConnectionKind::Llm => AiProviderResource::getUrl('index', tenant: $connection->tenant),
            default => ConnectionResource::getUrl('index', tenant: $connection->tenant),
        };
        $code = $request->query('code');

        // An answer to a question this session did not ask, or a no.
        if (! is_string($code) || $code === '' || ! hash_equals((string) ($pending['state'] ?? ''), (string) $request->query('state'))) {
            Notification::make()->title("{$connection->name} was not connected")->body('Signing in there did not complete. Try again.')->danger()->send();

            return redirect()->to($back);
        }

        try {
            $granted = $driver->oauthExchange($code, route('connections.oauth.callback'));
            $tokens->store($connection, $granted, $driver->oauthConnected($connection, $granted['access_token']));

            Notification::make()->title("{$connection->name} is connected")->body('It reads as the account that signed in, for as long as that account leaves it allowed.')->success()->send();
        } catch (ConnectionFailed $exception) {
            Notification::make()->title("{$connection->name} was not connected")->body($exception->getMessage())->danger()->persistent()->send();
        }

        return redirect()->to($back);
    }

    /**
     * The connection's service, for someone who may change the connection and a
     * service that can be signed in at.
     */
    protected function driver(Request $request, Connection $connection): ConnectsWithOAuth
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->canAccessTenant($connection->tenant), 404);

        $driver = $connection->driver();

        // Set up here, and on for this tenant.
        abort_unless($driver instanceof ConnectsWithOAuth && Integrations::offered($driver, $connection->tenant), 404);

        return $driver;
    }
}
