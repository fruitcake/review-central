<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Exceptions\CheckoutFailed;
use App\Models\Repository;
use App\SourceControl\PullRef;
use Illuminate\Support\Facades\Process;

/**
 * A fresh clone of a repository for one review, made on the worker itself, so the
 * credentials it takes never reach the container that reads the code. A Cloudflare
 * sandbox can clone for itself instead, through the Worker: see forSandbox().
 *
 * Blobless rather than shallow: every commit and tree is there, so the merge base
 * with the destination and the commit the last review ended on are always found,
 * while file contents are only fetched for what is checked out and compared. The
 * reviewer cannot fetch what is missing, so the diffs it will read are made here
 * once, which brings in their contents.
 */
class Checkout
{
    /**
     * Seconds one git command may take.
     */
    protected const TIMEOUT = 600;

    /**
     * Clones the branch into an empty directory. A pull request from a fork, which
     * goes by its pull ref ({@see PullRef}), is fetched through that ref on top of
     * the repository's main branch: the destination is fetched later, when the
     * range is prepared.
     *
     * @return string the commit that is checked out
     *
     * @throws CheckoutFailed
     */
    public function clone(Repository $repository, string $branch, ?string $commit, string $directory): string
    {
        $this->guard($branch);

        $access = $repository->sourceControl()->cloneAccess();
        $pullRef = PullRef::id($branch) !== null;

        $this->git($repository, null, [
            'clone', '--filter=blob:none', '--no-tags', '--single-branch', '--no-recurse-submodules',
            ...($pullRef ? [] : ['--branch', $branch]), '--', $access['url'], $directory,
        ], 'clone');

        if ($pullRef) {
            $this->git($repository, $directory, ['fetch', '--filter=blob:none', '--no-tags', 'origin', "+refs/{$branch}:refs/remotes/origin/{$branch}"], 'fetch');
            $this->git($repository, $directory, ['checkout', '--detach', "refs/remotes/origin/{$branch}"], 'checkout');
        }

        if ($commit !== null) {
            $this->guard($commit);
            $this->git($repository, $directory, ['checkout', '--detach', $commit], 'checkout');
        }

        return trim($this->git($repository, $directory, ['rev-parse', 'HEAD'], 'rev-parse'));
    }

    /**
     * Clones an open pull request whose branch is in a fork, through the ref the
     * host keeps it under in this repository (GitHub's pull/<number>/head): the
     * destination branch, and the pull request's head checked out on top of it.
     *
     * @throws CheckoutFailed
     */
    public function clonePullRef(Repository $repository, string $destination, string $ref, string $directory): string
    {
        $this->guard($destination);

        if (preg_match('#^pull/[0-9]+/head$#', $ref) !== 1) {
            throw CheckoutFailed::unsafeName($ref);
        }

        $access = $repository->sourceControl()->cloneAccess();

        $this->git($repository, null, [
            'clone', '--filter=blob:none', '--no-tags', '--single-branch', '--no-recurse-submodules',
            '--branch', $destination, '--', $access['url'], $directory,
        ], 'clone');

        $this->git($repository, $directory, ['fetch', '--filter=blob:none', '--no-tags', 'origin', "+refs/{$ref}:refs/remotes/origin/pull-head"], 'fetch');
        $this->git($repository, $directory, ['checkout', '--detach', 'refs/remotes/origin/pull-head'], 'checkout');

        return trim($this->git($repository, $directory, ['rev-parse', 'HEAD'], 'rev-parse'));
    }

    /**
     * Clones a pull request that was merged, as it was: the commits that went in
     * checked out, and the destination branch put back to where it was before them,
     * so everything that compares with it sees the pull request's own change.
     *
     * A merge commit has the branch as its second parent. A squash is the change
     * itself, on top of the destination as it was.
     *
     * @return array{head: string, base: string}
     *
     * @throws CheckoutFailed
     */
    public function cloneMerged(Repository $repository, string $destination, string $mergeCommit, string $directory): array
    {
        $this->guard($destination);

        if (preg_match('/^[0-9a-f]{7,40}$/', $mergeCommit) !== 1) {
            throw CheckoutFailed::unsafeName($mergeCommit);
        }

        $access = $repository->sourceControl()->cloneAccess();

        $this->git($repository, null, [
            'clone', '--filter=blob:none', '--no-tags', '--single-branch', '--no-recurse-submodules',
            '--branch', $destination, '--', $access['url'], $directory,
        ], 'clone');

        $commits = preg_split('/\s+/', trim($this->git($repository, $directory, ['rev-list', '--parents', '-n', '1', $mergeCommit], 'rev-list'))) ?: [];

        if (count($commits) < 2) {
            throw CheckoutFailed::during('rev-list', "{$mergeCommit} has no commit before it on {$destination}.");
        }

        [$head, $base] = count($commits) > 2 ? [$commits[2], $commits[1]] : [$commits[0], $commits[1]];

        $this->git($repository, $directory, ['checkout', '--detach', $head], 'checkout');
        $this->git($repository, $directory, ['update-ref', "refs/remotes/origin/{$destination}", $base], 'update-ref');
        $this->compare($repository, $directory, [$base]);

        return ['head' => trim($this->git($repository, $directory, ['rev-parse', 'HEAD'], 'rev-parse')), 'base' => $base];
    }

    /**
     * Fetches the destination branch and the contents of what the review compares:
     * the pull request against its merge base, and against the commit the last
     * review ended on.
     *
     * @throws CheckoutFailed when the destination cannot be fetched
     */
    public function prepareRange(Repository $repository, string $directory, string $destination, ?string $since): void
    {
        $this->guard($destination);

        $this->git($repository, $directory, [
            'fetch', '--filter=blob:none', '--no-tags', 'origin',
            "+refs/heads/{$destination}:refs/remotes/origin/{$destination}",
        ], 'fetch');

        $base = trim($this->git($repository, $directory, ['merge-base', "origin/{$destination}", 'HEAD'], 'merge-base', tolerant: true));

        $this->compare($repository, $directory, [$base, $since]);
    }

    /**
     * Makes the diffs the reviewer will read, which brings in their contents.
     *
     * @param  array<int, string|null>  $from
     */
    protected function compare(Repository $repository, string $directory, array $from): void
    {
        foreach (array_filter($from) as $commit) {
            // A commit that is gone after a force push has nothing to compare with.
            if (preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
                $this->git($repository, $directory, ['diff', '--no-ext-diff', '--no-textconv', '--stat', $commit, 'HEAD'], 'diff', tolerant: true);
            }
        }
    }

    /**
     * Moves a clone that already reviewed one commit to the branch as it is now,
     * and throws away whatever the review left in the tree.
     *
     * Only for a clone whose git directory the reviewer could not write: git reads
     * its configuration from there, and runs here with the host's credentials.
     *
     * @return string the commit that is checked out
     *
     * @throws CheckoutFailed
     */
    public function update(Repository $repository, string $directory, string $branch, ?string $commit): string
    {
        $this->guard($branch);

        // A pull ref is a ref of its own, not under heads/.
        $source = PullRef::id($branch) !== null ? "refs/{$branch}" : "refs/heads/{$branch}";

        $this->git($repository, $directory, [
            'fetch', '--filter=blob:none', '--no-tags', 'origin',
            "+{$source}:refs/remotes/origin/{$branch}",
        ], 'fetch');

        if ($commit !== null) {
            $this->guard($commit);
        }

        $this->git($repository, $directory, ['checkout', '--force', '--detach', $commit ?? "origin/{$branch}"], 'checkout');
        // Twice forced: also what is ignored, and directories with a repository of their own.
        $this->git($repository, $directory, ['clean', '-ffdx'], 'clean');

        return trim($this->git($repository, $directory, ['rev-parse', 'HEAD'], 'rev-parse'));
    }

    /**
     * The commit a ref is at on the repository's host, asked without a clone.
     *
     * @throws CheckoutFailed when the host does not have the ref
     */
    public function remoteCommit(Repository $repository, string $ref): string
    {
        $this->guard($ref);

        $access = $repository->sourceControl()->cloneAccess();
        $listed = $this->git($repository, null, ['ls-remote', '--', $access['url'], $ref], 'ls-remote');

        foreach (preg_split('/\R/', trim($listed)) ?: [] as $line) {
            [$commit, $name] = array_pad(explode("\t", $line, 2), 2, '');

            if ($name === $ref && preg_match('/^[0-9a-f]{40}$/', $commit) === 1) {
                return $commit;
            }
        }

        throw CheckoutFailed::during('ls-remote', "{$ref} is not on the repository's host.");
    }

    /**
     * Whether the repository is cloned without a credential: one anyone may read.
     */
    public function anonymous(Repository $repository): bool
    {
        return blank($repository->sourceControl()->cloneAccess()['password'] ?? null);
    }

    /**
     * Whether a Cloudflare sandbox can clone the repository: over HTTPS, and with a
     * credential only through the Worker, which stands between it and a host on
     * the usual port alone.
     */
    public function reachableFromSandbox(Repository $repository): bool
    {
        $url = parse_url($repository->sourceControl()->cloneAccess()['url']);

        return is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && (($url['port'] ?? 443) === 443 || $this->anonymous($repository))
            && ! isset($url['user'])
            && filled($url['host'] ?? null)
            && filled($url['path'] ?? null);
    }

    /**
     * What a Cloudflare sandbox is told to clone, for the Worker to do it there: the
     * ref fetched under refs/remotes/origin/<name>, as a clone here has it, the
     * commit checked out, and the destination branch beside it. A merged pull
     * request's destination is put back to `base`, where it was before the merge.
     * The comparison with `since`, the commit the last review ended on, is fetched
     * with the clone, as prepareRange() does here.
     *
     * The host's credentials go to the Worker as the header git would send. It adds
     * that header to reading this repository only; the sandbox never has it.
     *
     * @return array{url: string, authorization: string|null, ref: string, name: string, commit: string, destination: string|null, base: string|null, since: string|null}
     *
     * @throws CheckoutFailed
     */
    public function forSandbox(Repository $repository, string $ref, string $name, string $commit, ?string $destination, ?string $base = null, ?string $since = null): array
    {
        foreach ([$ref, $name, ...array_filter([$destination])] as $part) {
            $this->guard($part);
        }

        // A commit that is gone after a force push has nothing to compare with.
        if ($since !== null && preg_match('/^[0-9a-f]{7,40}$/', $since) !== 1) {
            $since = null;
        }

        foreach (array_filter([$commit, $base]) as $hash) {
            if (preg_match('/^[0-9a-f]{7,40}$/', $hash) !== 1) {
                throw CheckoutFailed::unsafeName($hash);
            }
        }

        $access = $repository->sourceControl()->cloneAccess();

        return [
            'url' => $access['url'],
            'authorization' => $this->anonymous($repository) ? null : 'Basic '.base64_encode("{$access['username']}:{$access['password']}"),
            'ref' => $ref,
            'name' => $name,
            'commit' => $commit,
            'destination' => $destination,
            'base' => $base,
            'since' => $since,
        ];
    }

    /**
     * A name handed to git as an argument must not read as an option.
     *
     * @throws CheckoutFailed
     */
    protected function guard(string $name): void
    {
        if ($name === '' || str_starts_with($name, '-') || preg_match('/[\s\x00-\x1f:^~?*\[\\\\]|\.\./', $name)) {
            throw CheckoutFailed::unsafeName($name);
        }
    }

    /**
     * @param  array<int, string>  $arguments
     *
     * @throws CheckoutFailed
     */
    protected function git(Repository $repository, ?string $directory, array $arguments, string $step, bool $tolerant = false): string
    {
        $access = $repository->sourceControl()->cloneAccess();

        // A repository anyone can clone is cloned as anyone: a header with no
        // password in it is a failed sign-in to some hosts, not no sign-in.
        $anonymous = blank($access['password'] ?? null);

        $process = Process::timeout(self::TIMEOUT)->env([
            // The credentials as configuration from the environment: not in the
            // arguments, and not written to the clone's own config.
            'GIT_CONFIG_COUNT' => '4',
            'GIT_CONFIG_KEY_0' => $anonymous ? 'credential.helper' : 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => $anonymous ? '' : 'Authorization: Basic '.base64_encode("{$access['username']}:{$access['password']}"),
            // Nothing the repository brings may run on the worker.
            'GIT_CONFIG_KEY_1' => 'core.hooksPath',
            'GIT_CONFIG_VALUE_1' => '/dev/null',
            'GIT_CONFIG_KEY_2' => 'core.fsmonitor',
            'GIT_CONFIG_VALUE_2' => 'false',
            'GIT_CONFIG_KEY_3' => 'protocol.file.allow',
            'GIT_CONFIG_VALUE_3' => 'never',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_LFS_SKIP_SMUDGE' => '1',
        ]);

        if ($directory !== null) {
            $process = $process->path($directory);
        }

        $result = $process->run(['git', ...$arguments]);

        if ($result->failed() && ! $tolerant) {
            throw CheckoutFailed::during($step, $result->errorOutput());
        }

        return $result->successful() ? $result->output() : '';
    }
}
