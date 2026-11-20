<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
use App\SourceControl\WebhookEvent;

/**
 * What a source control webhook delivery does once it is known to be genuine:
 * proof that the webhook is in place, and the review it asks for.
 */
class WebhookDeliveries
{
    public function __construct(protected ReviewRequest $reviewRequest) {}

    public function deliver(Connection $connection, WebhookEvent $event): void
    {
        // Proof that the webhook is in place, for whoever checks a repository's setup.
        $connection->forceFill(['webhook_received_at' => now()])->save();

        $repository = $this->repository($connection, $event->workspace, $event->slug);

        if ($repository !== null) {
            $repository->forceFill(['webhook_received_at' => now()])->save();
            $this->reviewRequest->handle($repository, $event);
        }
    }

    public function repository(Connection $connection, string $workspace, string $slug): ?Repository
    {
        return Repository::query()
            ->where('connection_id', $connection->getKey())
            ->where('workspace', $workspace)
            ->where('slug', $slug)
            ->first();
    }
}
