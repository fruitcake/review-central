<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\ReviewRun;
use App\Services\Broadcasts;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * What a container review printed, kept in a file while it runs so the review's
 * page can show it as it goes.
 *
 * A file per build rather than a column: it is appended to many times a second,
 * and nothing but that page reads it. It may quote the repository's code, so it is
 * the tenant's to read and nobody else's, and it is removed after a while.
 */
class RunLog
{
    /** The end of the log that a page shows: enough to follow, small enough to send every few seconds. */
    public const TAIL_BYTES = 200_000;

    public function enabled(): bool
    {
        return (bool) config('review.container.log.enabled');
    }

    public function path(int $repositoryId, int $build): string
    {
        return rtrim((string) config('review.container.log.directory'), '/')."/{$repositoryId}/{$build}.log";
    }

    /**
     * Something to write a step's output to, or null when logs are not kept.
     *
     * @param  array<string, string>  $environment  the step's environment, whose secrets are kept out of the log
     */
    public function writer(int $repositoryId, int $build, array $environment = []): ?RunLogWriter
    {
        if (! $this->enabled()) {
            return null;
        }

        $path = $this->path($repositoryId, $build);
        File::ensureDirectoryExists(dirname($path));

        return new RunLogWriter(
            $path,
            $environment,
            fn () => Broadcasts::logGrew($repositoryId, $build),
            $this->disk() === null ? null : fn () => $this->copy($repositoryId, $build),
            fn () => app(CheckProgress::class)->show($repositoryId, $build),
        );
    }

    /**
     * The disk the logs are also kept on, for a page served from another server
     * than the worker that writes them.
     */
    protected function disk(): ?Filesystem
    {
        $disk = config('review.container.log.disk');

        return is_string($disk) && $disk !== '' ? Storage::disk($disk) : null;
    }

    protected function key(int $repositoryId, int $build): string
    {
        return "review/logs/{$repositoryId}/{$build}.log";
    }

    /**
     * Copies the log as it is now to the disk. Whole each time: a bucket appends
     * nothing, and a log is small. Never in the way of the review.
     */
    public function copy(int $repositoryId, int $build): void
    {
        $path = $this->path($repositoryId, $build);

        if (is_file($path)) {
            rescue(fn () => $this->disk()?->put($this->key($repositoryId, $build), (string) file_get_contents($path)), null, false);
        }
    }

    /**
     * The end of a run's log, or null when there is none: another runner, logs
     * turned off, or a log that was removed since.
     *
     * @return array{text: string, truncated: bool}|null
     */
    public function tail(ReviewRun $run): ?array
    {
        if ($run->build_number === null) {
            return null;
        }

        $path = $this->path((int) $run->repository_id, (int) $run->build_number);

        // Not on this server: the worker's copy on the disk, where it keeps one.
        if (! is_file($path)) {
            $text = rescue(fn (): ?string => $this->disk()?->get($this->key((int) $run->repository_id, (int) $run->build_number)), null, false);

            if ($text === null) {
                return null;
            }

            $from = max(0, strlen($text) - self::TAIL_BYTES);
            $text = (string) substr($text, $from);
        } else {
            $size = (int) filesize($path);
            $from = max(0, $size - self::TAIL_BYTES);
            $text = (string) file_get_contents($path, false, null, $from);
        }

        if ($from > 0) {
            // From the first whole line.
            $text = (string) substr($text, (int) strpos($text, "\n") + 1);
        }

        return ['text' => mb_scrub($text, 'UTF-8'), 'truncated' => $from > 0];
    }

    /**
     * Removes the logs older than the days they are kept.
     */
    public function prune(): int
    {
        $directory = (string) config('review.container.log.directory');

        $before = now()->subDays((int) config('review.container.log.days'))->getTimestamp();
        $removed = 0;
        $disk = $this->disk();

        // The copies first, which outlive a worker that was replaced.
        foreach ($disk?->allFiles('review/logs') ?? [] as $file) {
            if ($disk->lastModified($file) < $before && $disk->delete($file)) {
                $removed++;
            }
        }

        foreach (is_dir($directory) ? File::allFiles($directory) : [] as $file) {
            if ($file->getMTime() < $before && File::delete($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }
}
