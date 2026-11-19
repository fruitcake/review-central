<?php

declare(strict_types=1);

namespace App\Connections;

use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use Illuminate\Support\Facades\Cache;

/**
 * The tokens of a connection that was made by signing in at the service.
 *
 * An access token lasts an hour or so; the refresh token gets the next one. Some
 * services hand out a new refresh token each time and retire the old, so the
 * exchange is done by one process at a time and what comes back is stored before
 * it is used.
 */
class OAuthTokens
{
    /** Refreshed this long before it runs out, so a request never leaves with one that is about to. */
    protected const MARGIN_SECONDS = 120;

    public static function has(Connection $connection): bool
    {
        return $connection->credential('oauth_access_token') !== null;
    }

    /**
     * Keeps what the service handed out.
     *
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int|null}  $tokens
     * @param  array<string, string>  $settings
     */
    public function store(Connection $connection, array $tokens, array $settings = []): void
    {
        $connection->credentials = array_filter([
            'oauth_access_token' => $tokens['access_token'],
            // A service that sends no new one means the old one still holds.
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $connection->credential('oauth_refresh_token'),
            'oauth_expires_at' => $tokens['expires_in'] === null ? null : (string) (now()->getTimestamp() + $tokens['expires_in']),
        ], fn (?string $value): bool => $value !== null) + ($connection->credentials ?? []);

        if ($settings !== []) {
            $connection->settings = $settings + ($connection->settings ?? []);
        }

        $connection->save();
    }

    /**
     * An access token that holds for the next request, refreshed when it would not.
     *
     * @throws ConnectionFailed when the service takes the refresh token no more: the person has to connect again
     */
    public function token(Connection $connection): string
    {
        if (! $this->expiring($connection)) {
            return (string) $connection->credential('oauth_access_token');
        }

        $driver = $connection->driver();
        $refresh = $connection->credential('oauth_refresh_token');

        if (! $driver instanceof ConnectsWithOAuth || $refresh === null) {
            throw new ConnectionFailed("{$connection->name} has to be connected again: its access ran out and cannot be renewed.");
        }

        // One at a time: a refresh token that rotates is spent by whoever uses it first.
        return Cache::lock("connection-oauth:{$connection->getKey()}", 30)->block(20, function () use ($connection, $driver): string {
            $connection->refresh();

            if (! $this->expiring($connection)) {
                return (string) $connection->credential('oauth_access_token');
            }

            $this->store($connection, $driver->oauthRefresh((string) $connection->credential('oauth_refresh_token')));

            return (string) $connection->credential('oauth_access_token');
        });
    }

    protected function expiring(Connection $connection): bool
    {
        $expires = $connection->credential('oauth_expires_at');

        return $expires !== null && (int) $expires - self::MARGIN_SECONDS <= now()->getTimestamp();
    }
}
