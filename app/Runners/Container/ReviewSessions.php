<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\Repository;
use App\Models\ReviewRun;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The reviewer's home, kept on the worker per repository and branch, so what it
 * read and found in one review of a pull request is there for the next.
 *
 * Claude Code keeps its session there. A later review of the same branch resumes
 * it and starts from what the last one knew, and a question someone asks on the
 * pull request is answered from it.
 *
 * Per branch, never wider, for the reason the Composer cache is per repository:
 * the reviewer runs with the pull request's code, which can write here.
 */
class ReviewSessions
{
    /**
     * What a reviewer's home holds that is no session: the CLIs it installed and
     * the caches of npm, Composer and the review's own tools, hundreds of
     * megabytes beside a session of a few. Kept where the home is, on the worker,
     * as a cache; never carried along to a disk or a sandbox, which only costs
     * time each review: a sandbox installs what it misses in a few seconds.
     */
    public const CACHES = ['.local', '.npm', '.tools', '.cache', '.composer'];

    /** Where run-claude.sh notes the session a review ended in. */
    protected const SESSION = '.review-session';

    /** How many reviews resumed the session this home holds. */
    protected const RESUMES = '.review-resumes';

    /**
     * What a trial's session is kept under: its own, apart from the branch's, so a
     * question on its page goes on from where it ended, and the pull request's own
     * reviews never resume a session nobody saw. The Composer cache stays shared:
     * that is per repository and not the reviewer's.
     */
    public static function forTrial(string $branch, int $build): string
    {
        return "{$branch}#trial-{$build}";
    }

    /**
     * What the session of a run is kept under: its branch's, or its own for a trial.
     */
    public static function forRun(ReviewRun $run): string
    {
        return $run->custom && $run->kind === 'pull-request' && $run->build_number !== null
            ? self::forTrial($run->branch, $run->build_number)
            : $run->branch;
    }

    /**
     * The home for a branch, made when it is not there, or null when none is kept.
     */
    public function home(Repository $repository, string $branch): ?string
    {
        $directory = $this->directory($repository, $branch);

        if ($directory === null) {
            return null;
        }

        File::ensureDirectoryExists($directory, 0700);
        $this->fetch($repository, $branch, $directory);
        // Also a home kept from before links were removed after each step.
        self::removeLinks($directory);
        // Its last use, which is what pruning goes by.
        touch($directory);

        return $directory;
    }

    /**
     * Keeps the session on the disk as well, where one is set, so a worker that
     * has not got it can pick it up. After each review, by the worker that ran it.
     */
    public function store(Repository $repository, string $branch): void
    {
        $directory = $this->directory($repository, $branch);
        $disk = $this->disk();

        if ($disk === null || $directory === null || ! is_file("{$directory}/".self::SESSION) || is_link("{$directory}/".self::SESSION)) {
            return;
        }

        $archive = "{$directory}.tar.gz";

        // Never in the way of the review: a session that is not kept is a review
        // that starts fresh.
        rescue(function () use ($disk, $directory, $archive, $repository, $branch): void {
            try {
                Process::timeout(300)->run(['tar', '-czf', $archive, ...self::excluded(), '-C', $directory, '.'])->throw();
                $stream = fopen($archive, 'r');

                if ($stream !== false) {
                    $disk->writeStream($this->key($repository, $branch), $stream);
                    is_resource($stream) && fclose($stream);
                    // As new as what was stored, so this worker does not fetch its own copy back.
                    touch("{$directory}/".self::SESSION, time() + 1);
                }
            } finally {
                File::delete($archive);
            }
        }, null, false);
    }

    /**
     * Brings the session from the disk when this worker has none of it, or an
     * older one than another worker stored there.
     */
    protected function fetch(Repository $repository, string $branch, string $directory): void
    {
        $disk = $this->disk();

        if ($disk === null) {
            return;
        }

        $archive = "{$directory}.tar.gz";

        rescue(function () use ($disk, $directory, $archive, $repository, $branch): void {
            $key = $this->key($repository, $branch);
            $own = is_file("{$directory}/".self::SESSION) ? (int) filemtime("{$directory}/".self::SESSION) : 0;

            // This worker's own copy is used unless another worker stored a later one.
            if (! $disk->exists($key) || $disk->lastModified($key) <= $own) {
                return;
            }

            $stream = $disk->readStream($key);

            if (! is_resource($stream)) {
                return;
            }

            try {
                file_put_contents($archive, $stream);
                fclose($stream);
                // Written by a reviewer that ran a pull request's code: no link in
                // it is kept, as none is trusted in a home on this worker.
                Process::timeout(300)->run(['tar', '-xzf', $archive, '-C', $directory, '--no-same-owner'])->throw();
                Process::timeout(60)->run(['find', $directory, '-type', 'l', '-delete']);
            } finally {
                File::delete($archive);
            }
        }, null, false);
    }

    /**
     * Removes the links from a home the reviewer had mounted. The pull request's
     * code ran there and can leave links to anywhere on the worker, which the
     * worker would follow when it next reads or writes the home.
     *
     * Not those inside the caches: the CLIs installed there are reached through
     * links, and the worker never reads or writes in them; tar leaves them out and
     * removing a home does not follow a link.
     */
    public static function removeLinks(string $home): void
    {
        if (! is_dir($home) || is_link($home)) {
            return;
        }

        $caches = collect(self::CACHES)->flatMap(fn (string $cache): array => ['-not', '-path', "{$home}/{$cache}/*"])->all();

        Process::timeout(120)->run(['find', $home, '-type', 'l', ...$caches, '-delete']);
    }

    /**
     * tar's arguments that leave the caches out of an archive of a home made from
     * inside it (`-C <home> .`).
     *
     * @return list<string>
     */
    public static function excluded(): array
    {
        return array_map(fn (string $cache): string => "--exclude=./{$cache}", self::CACHES);
    }

    protected function disk(): ?Filesystem
    {
        $disk = config('review.container.sessions.disk');

        return is_string($disk) && $disk !== '' ? Storage::disk($disk) : null;
    }

    protected function key(Repository $repository, string $branch): string
    {
        return "review/sessions/{$repository->getKey()}/".substr(hash('sha256', $branch), 0, 32).'.tar.gz';
    }

    /**
     * Whether the next review in this home resumes the session it holds.
     *
     * Only so many times in a row: a session that has followed many commits
     * carries what was true of code that is gone, and grows with each. The review
     * after that starts fresh.
     */
    public function resumes(string $home): bool
    {
        if (! is_file("{$home}/".self::SESSION) || is_link("{$home}/".self::SESSION)) {
            return false;
        }

        if ($this->count($home) < (int) config('review.container.sessions.resumes')) {
            return true;
        }

        $this->reset($home);

        return false;
    }

    /**
     * Notes that a review resumed the session.
     */
    public function resumed(string $home): void
    {
        $count = $this->count($home) + 1;
        $path = "{$home}/".self::RESUMES;

        // Written in place of a link, never through it.
        if (is_link($path)) {
            unlink($path);
        }

        File::put($path, (string) $count);
    }

    /**
     * A copy of what a session needs, in a directory of its own, for a question:
     * its answer is not part of the review, and a review may be using the home.
     *
     * @return string|null the copy, which is the caller's to remove; null when the branch has no session
     */
    public function copy(Repository $repository, string $branch, string $to): ?string
    {
        $home = $this->directory($repository, $branch);

        if ($home !== null && $this->disk() !== null) {
            File::ensureDirectoryExists($home, 0700);
            $this->fetch($repository, $branch, $home);
        }

        if ($home === null || ! is_file("{$home}/".self::SESSION) || is_link("{$home}/".self::SESSION)) {
            return null;
        }

        File::ensureDirectoryExists($to, 0700);
        File::copy("{$home}/".self::SESSION, "{$to}/".self::SESSION);

        if (is_dir("{$home}/.claude") && ! is_link("{$home}/.claude")) {
            $this->copyWithoutLinks("{$home}/.claude", "{$to}/.claude");
        }

        return $to;
    }

    /**
     * Copies a directory of the home and leaves out every link in it, where
     * File::copyDirectory() would copy what a link points to.
     */
    protected function copyWithoutLinks(string $from, string $to): void
    {
        File::ensureDirectoryExists($to, 0700);

        // A linked directory is not descended into: hasChildren() follows no links.
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }

            $target = $to.substr($item->getPathname(), strlen($from));

            if ($item->isDir()) {
                File::ensureDirectoryExists($target, 0700);
            } elseif ($item->isFile()) {
                File::copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Removes what was kept for a branch: its pull request was merged or declined.
     */
    public function forget(Repository $repository, string $branch): void
    {
        // A review of the branch that is still running has its home mounted, and
        // would lose its session and its transcript, and with them its cost. The
        // home goes when pruning finds it unused.
        $busy = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('branch', $branch)
            ->whereIn('status', [ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_QUEUED])
            ->exists();

        if ($busy) {
            return;
        }

        // The branch's own session, and those of the trials on it: each holds the
        // reviewer's transcript of the code, which has no use once it is merged.
        $trials = ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('branch', $branch)
            ->where('custom', true)
            ->where('kind', 'pull-request')
            ->whereNotNull('build_number')
            ->pluck('build_number')
            ->map(fn (int $build): string => self::forTrial($branch, $build));

        foreach ([$branch, ...$trials] as $session) {
            $directory = $this->directory($repository, $session);

            if ($directory !== null) {
                File::deleteDirectory($directory);
            }

            rescue(fn () => $this->disk()?->delete($this->key($repository, $session)), null, false);
        }
    }

    /**
     * Removes the homes of branches that had no review for the days they are kept.
     */
    public function prune(): int
    {
        $root = $this->root();

        if ($root === null || ! is_dir($root)) {
            return 0;
        }

        $before = now()->subDays((int) config('review.container.sessions.days'))->getTimestamp();
        $removed = 0;

        foreach (File::directories($root) as $repository) {
            foreach (File::directories($repository) as $branch) {
                if (filemtime($branch) < $before && File::deleteDirectory($branch)) {
                    $removed++;
                }
            }

            if (File::isEmptyDirectory($repository)) {
                File::deleteDirectory($repository);
            }
        }

        return $removed;
    }

    protected function count(string $home): int
    {
        $path = "{$home}/".self::RESUMES;

        return is_file($path) && ! is_link($path) ? (int) File::get($path) : 0;
    }

    /**
     * Forgets the session and keeps the rest of the home, such as the tools a
     * review installed there. Also for a session a review could not resume: the
     * next review of the branch then starts fresh, where it would fail the same way.
     */
    public function reset(string $home): void
    {
        File::delete(["{$home}/".self::SESSION, "{$home}/".self::RESUMES]);

        if (is_dir("{$home}/.claude/projects") && ! is_link("{$home}/.claude") && ! is_link("{$home}/.claude/projects")) {
            File::deleteDirectory("{$home}/.claude/projects");
        }
    }

    protected function directory(Repository $repository, string $branch): ?string
    {
        $root = $this->root();

        // A hash, because a branch name is not a directory name.
        return $root === null ? null : "{$root}/{$repository->getKey()}/".substr(hash('sha256', $branch), 0, 32);
    }

    protected function root(): ?string
    {
        $root = rtrim((string) config('review.container.sessions.directory'), '/');

        return $root === '' ? null : $root;
    }
}
