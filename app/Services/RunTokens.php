<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The credential of one review run this server started itself: good for one
 * repository, branch and commit, and for as long as the run may take.
 *
 * It says which repository a step belongs to, and which branch and commit it may
 * ask about and report on.
 */
class RunTokens
{
    public const PREFIX = 'run_';

    /**
     * @param  int|null  $build  the number the run goes by, which holds its lock and names its record
     */
    public function issue(Repository $repository, string $branch, string $commit, int $seconds, ?int $build = null): string
    {
        $token = self::PREFIX.Str::random(48);

        Cache::put($this->key($token), [
            'repository_id' => $repository->getKey(),
            'branch' => $branch,
            'commit' => $commit,
            'build' => $build,
        ], $seconds);

        return $token;
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * The repository a token was issued for, or null when it is not one, has
     * expired or was revoked.
     */
    public function repositoryId(string $token): ?int
    {
        return $this->find($token)['repository_id'] ?? null;
    }

    /**
     * Whether a token was issued for exactly this repository, branch and commit,
     * and for this build where the call names one.
     *
     * The build is what a run holds the review lock as and what its record is
     * kept under. Left to the call, a run could take the lock as another build
     * of its commit, release that build's lock or write over its record.
     */
    public function vouchesFor(Repository $repository, string $token, string $branch, ?string $commit, ?string $build = null): bool
    {
        $run = $this->find($token);

        return $run !== null
            && $run['repository_id'] === $repository->getKey()
            && $run['branch'] === $branch
            && ($commit === null || ReviewContext::isSameCommit($run['commit'], $commit))
            && ($build === null || ($run['build'] ?? null) === null || (string) $run['build'] === $build);
    }

    /**
     * Refuses a call whose token was not issued for this branch and commit: the
     * lock, the run's record and the published review are all keyed on them.
     */
    public function authorize(Request $request, Repository $repository, string $branch, ?string $commit, ?string $build = null): void
    {
        abort_unless(
            $this->vouchesFor($repository, (string) $request->header('X-Api-Key'), $branch, $commit, $build),
            403,
            'This run was started for another branch, commit or build.',
        );
    }

    /**
     * @return array{repository_id: int, branch: string, commit: string, build?: int|null}|null
     */
    protected function find(string $token): ?array
    {
        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $run = Cache::get($this->key($token));

        return is_array($run) && isset($run['repository_id'], $run['branch'], $run['commit']) ? $run : null;
    }

    protected function key(string $token): string
    {
        return 'review-run-token:'.hash('sha256', $token);
    }
}
