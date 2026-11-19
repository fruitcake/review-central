<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\Repository;
use Illuminate\Support\Facades\File;

/**
 * What Composer downloaded in a container review, kept on the worker so the next
 * review of the same repository installs from disk.
 *
 * One directory per repository, never a wider one. The reviewer runs with the
 * pull request's code, which can write here, and Composer installs what it
 * finds: a cache shared between repositories would let a pull request in one
 * leave something for the reviews of another tenant.
 *
 * Within a repository that is accepted: whoever can open a pull request there can
 * reach the reviews of its other pull requests this way.
 */
class ComposerCache
{
    /**
     * The directory of a repository, made when it is not there, or null when nothing is kept.
     */
    public function directory(Repository $repository): ?string
    {
        $root = $this->root();

        if ($root === null) {
            return null;
        }

        $directory = "{$root}/{$repository->getKey()}";

        File::ensureDirectoryExists($directory, 0700);
        // Its last use, which is what pruning goes by.
        touch($directory);

        return $directory;
    }

    /**
     * Removes the caches of repositories that had no review for the days they are kept.
     */
    public function prune(): int
    {
        $root = $this->root();

        if ($root === null || ! is_dir($root)) {
            return 0;
        }

        $before = now()->subDays((int) config('review.container.cache_days'))->getTimestamp();
        $removed = 0;

        foreach (File::directories($root) as $directory) {
            if (filemtime($directory) < $before && File::deleteDirectory($directory)) {
                $removed++;
            }
        }

        return $removed;
    }

    protected function root(): ?string
    {
        $root = rtrim((string) config('review.container.cache_directory'), '/');

        return $root === '' ? null : $root;
    }
}
