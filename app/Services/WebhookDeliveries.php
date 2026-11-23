<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
use App\SourceControl\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * What a source control webhook delivery does once it is known to be genuine:
 * proof that the webhook is in place, and the review it asks for. A connection
 * set to add repositories adds one it hears of for the first time, reviewing
 * nothing by itself, so its pull requests are kept from then on.
 */
class WebhookDeliveries
{
    public function __construct(protected ReviewRequest $reviewRequest, protected RepositorySetup $setup) {}

    public function deliver(Connection $connection, WebhookEvent $event): void
    {
        // Proof that the webhook is in place, for whoever checks a repository's setup.
        $connection->forceFill(['webhook_received_at' => now()])->save();

        $repository = $this->repository($connection, $event->workspace, $event->slug) ?? $this->add($connection, $event);

        if ($repository !== null) {
            $repository->forceFill(['webhook_received_at' => now()])->save();
            $this->reviewRequest->handle($repository, $event);
        }
    }

    /**
     * The repository the event is about, added to the connection's tenant when the
     * connection is set to. Not one that was removed here: someone took it off on
     * purpose, and adding it again is theirs to do.
     */
    protected function add(Connection $connection, WebhookEvent $event): ?Repository
    {
        if (! $connection->addsRepositories() || blank($event->workspace) || blank($event->slug) || ! $connection->reachesOwner($event->workspace)) {
            return null;
        }

        $removed = Repository::onlyTrashed()
            ->where('connection_id', $connection->getKey())
            ->where('workspace', $event->workspace)
            ->where('slug', $event->slug)
            ->exists();

        if ($removed) {
            return null;
        }

        try {
            return Repository::query()->create([
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->getKey(),
                'reviews_enabled' => false,
            ] + $this->setup->complete(['workspace' => $event->workspace, 'slug' => $event->slug], $connection));
        } catch (UniqueConstraintViolationException) {
            // Another delivery for it, at the same moment, added it first.
            return $this->repository($connection, $event->workspace, $event->slug);
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
