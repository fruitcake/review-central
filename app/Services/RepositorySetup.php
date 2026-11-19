<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use Throwable;

/**
 * What a new repository gets besides what was chosen for it.
 */
class RepositorySetup
{
    /**
     * Completes what the form sent: the workspace of the connection, the
     * installation's runner when the form offered no choice, and the stack the
     * repository turns out to be built on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function complete(array $data, Connection $connection): array
    {
        // The connection's own, unless it is one for any owner's repositories:
        // then the repository says whose it is.
        $data['workspace'] = (string) ($connection->setting('workspace') ?? $data['workspace'] ?? '');

        if (blank($data['review_stack'] ?? null)) {
            $data['review_stack'] = $this->detectStack($connection, $data['workspace'], (string) ($data['slug'] ?? ''));
        }

        return $data;
    }

    /**
     * Adds the repository, or brings back the one that was removed, on this
     * connection or another to the same host: with its history, and its own
     * settings as they were.
     *
     * @param  array<string, mixed>  $data  as the form sent it
     */
    public function add(Tenant $tenant, Connection $connection, array $data): Repository
    {
        $removed = Repository::onlyTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->where('workspace', (string) ($connection->setting('workspace') ?? $data['workspace'] ?? ''))
            ->where('slug', (string) ($data['slug'] ?? ''))
            ->get()
            ->first(fn (Repository $repository): bool => $repository->integration->sameHostAs($connection));

        if ($removed !== null) {
            $removed->restore();
            $removed->update(['connection_id' => $connection->getKey()]);

            return $removed;
        }

        return Repository::query()->create(['tenant_id' => $tenant->getKey(), 'connection_id' => $connection->getKey()] + $this->complete($data, $connection));
    }

    /**
     * Best effort: a repository whose stack cannot be read is added without one,
     * and the stack can be set on it by hand.
     *
     * @return list<string>
     */
    public function detectStack(Connection $connection, string $workspace, string $slug): array
    {
        $repository = (new Repository(['workspace' => $workspace, 'slug' => $slug]))->setRelation('integration', $connection);

        try {
            return ReviewStack::fromComposer($repository->sourceControl()->getFile('composer.json'));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
