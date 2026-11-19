<?php

declare(strict_types=1);

namespace App\Connections;

use App\Models\Connection;
use App\Models\Repository;
use App\SourceControl\SourceControl;
use App\SourceControl\WebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

/**
 * A host for repositories and pull requests.
 */
interface SourceControlDriver extends ConnectionDriver
{
    public function client(Repository $repository): SourceControl;

    /**
     * The repositories of the connection that match what was typed, newest first,
     * for choosing one by name.
     *
     * @return array<string, string> the name in the address to the name to show
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function searchRepositories(Connection $connection, string $search): array;

    /**
     * Adds the connection's webhook on the host, for every repository the
     * connection reaches, or brings the one that is there up to date.
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function installWebhook(Connection $connection, string $url): void;

    /**
     * Reads a webhook delivery, or null when its signature does not match.
     */
    public function webhookEvent(Connection $connection, Request $request): ?WebhookEvent;
}
