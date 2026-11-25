<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
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
            // The one on this connection first: it needs no move, which another
            // removed copy here would be in the way of.
            ->sortByDesc(fn (Repository $repository): bool => $repository->connection_id === $connection->getKey())
            ->first(fn (Repository $repository): bool => $repository->integration->sameHostAs($connection) && $connection->reachesOwner($repository->workspace));

        if ($removed !== null) {
            // Back and moved in one save, so a move that fails brings nothing back.
            // Its own settings stay as they were, but whether it is reviewed by
            // itself is what the form just said.
            $removed->forceFill(['connection_id' => $connection->getKey(), 'deleted_at' => null]
                + (array_key_exists('reviews_enabled', $data) ? ['reviews_enabled' => (bool) $data['reviews_enabled']] : []))->save();

            return $removed;
        }

        return Repository::query()->create(['tenant_id' => $tenant->getKey(), 'connection_id' => $connection->getKey()] + $this->complete($data, $connection));
    }

    /**
     * Saves an edit. A repository moved to a connection where a removed copy of it
     * is, as when it was added there once and removed again, changes places with
     * that copy: the database holds one per connection, removed or not, and both
     * keep their history.
     *
     * @param  array<string, mixed>  $data  as the form sent it
     */
    public function update(Repository $repository, array $data): Repository
    {
        return DB::transaction(function () use ($repository, $data): Repository {
            $from = $repository->connection_id;
            $to = (int) ($data['connection_id'] ?? $from);
            $slug = (string) ($data['slug'] ?? $repository->slug);

            $removed = $to === $from ? null : Repository::onlyTrashed()
                ->where('connection_id', $to)
                ->where('workspace', $repository->workspace)
                ->where('slug', $slug)
                ->first();

            if ($removed === null) {
                $repository->update($data);

                return $repository;
            }

            // Out of the way for a moment, under a name no repository has.
            $removed->forceFill(['slug' => "{$slug}~{$removed->getKey()}"])->save();
            $repository->update($data);
            $removed->forceFill(['connection_id' => $from, 'slug' => $slug])->save();

            return $repository;
        });
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
            $source = $repository->sourceControl();

            // Each file on its own: one that cannot be read leaves what the other says.
            return ReviewStack::fromFiles(fn (string $path): ?string => rescue(fn (): ?string => $source->getFile($path)));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
