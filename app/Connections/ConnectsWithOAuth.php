<?php

declare(strict_types=1);

namespace App\Connections;

use App\Exceptions\ConnectionFailed;
use App\Models\Connection;

/**
 * A service a connection can also be given access to by signing in there, instead
 * of by pasting a token: this installation is registered with the service as an
 * app, and the person connecting says yes on the service's own page.
 *
 * The connection then acts as that person, for as long as they leave it allowed.
 */
interface ConnectsWithOAuth
{
    /**
     * Whether this installation is registered with the service: it has the app's
     * id and secret.
     */
    public function oauthConfigured(): bool;

    /**
     * Which of the installation's apps this is, as Integrations names it: what a
     * tenant can have turned off.
     */
    public function oauthIntegration(): string;

    /**
     * Where the person is sent to say yes.
     */
    public function oauthAuthorizeUrl(string $redirect, string $state): string;

    /**
     * Trades the code the service sent the person back with for tokens.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     *
     * @throws ConnectionFailed
     */
    public function oauthExchange(string $code, string $redirect): array;

    /**
     * A new access token for one that ran out, and a new refresh token where the
     * service hands one out each time.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     *
     * @throws ConnectionFailed
     */
    public function oauthRefresh(string $refreshToken): array;

    /**
     * What else the connection has to know once it has its tokens, such as which
     * of the person's sites it is for. Returns settings to keep.
     *
     * @return array<string, string>
     *
     * @throws ConnectionFailed
     */
    public function oauthConnected(Connection $connection, string $accessToken): array;
}
