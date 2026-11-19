<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
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
