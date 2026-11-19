<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saving and checking a tenant's connections.
 */
class Connections
{
    /**
     * Fills in what a new connection needs besides what was typed: a source
     * control connection gets the address and the secret of its webhook.
     */
    public function prepare(Connection $connection): void
    {
        if ($connection->kind === ConnectionKind::SourceControl && $connection->webhook_key === null) {
            $connection->webhook_key = Str::lower(Str::random(32));
            $connection->webhook_secret = Str::random(40);
        }
    }

    /**
     * The credentials to store after an edit: a secret left empty keeps its value.
     *
     * @param  array<string, mixed>  $entered
     * @return array<string, mixed>
     */
    public function mergeCredentials(Connection $connection, array $entered): array
    {
        return array_filter($entered, fn (mixed $value): bool => filled($value)) + ($connection->credentials ?? []);
    }

    /**
     * Whether the connection's credentials work, as a sentence to show.
     *
     * @throws ConnectionFailed
     */
    public function test(Connection $connection): void
    {
        try {
            $connection->driver()->test($connection);
        } catch (ConnectionFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ConnectionFailed('The service could not be reached: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Adds the connection's webhook on its host, for all its repositories.
     *
     * @throws ConnectionFailed
     */
    public function installWebhook(Connection $connection): void
    {
        $driver = $connection->driver();
        $url = $this->webhookUrl($connection);

        if (! $driver instanceof SourceControlDriver || $url === null) {
            throw new ConnectionFailed('This connection has no webhook.');
        }

        try {
            $driver->installWebhook($connection, $url);
        } catch (RequestException $exception) {
            throw new ConnectionFailed(match ($exception->response->status()) {
                401, 403 => 'The token may not manage webhooks: give it the webhook scope, or add the webhook to each repository by hand.',
                default => "The host answered {$exception->response->status()}.",
            }, previous: $exception);
        } catch (ConnectionException $exception) {
            throw new ConnectionFailed('The host could not be reached: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function webhookUrl(Connection $connection): ?string
    {
        return $connection->webhook_key === null ? null : route('api.webhooks', ['key' => $connection->webhook_key]);
    }
}
