<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionKind;
use App\Exceptions\ReviewTrialRefused;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Runners\Container\Trial;
use App\Runners\DiffRunner;
use App\Runners\Runners;
use App\SourceControl\PullRequestLink;
use Throwable;

/**
 * Tries a review on a pull request someone points at: open or merged, in a
 * repository that is set up here or not. The review is recorded and kept off the
 * pull request, so two setups can be compared on the same work.
 */
class ReviewTrial
{
    public function __construct(
        protected Runners $runners,
        protected RepositorySetup $setup,
        protected DiffRunner $diff,
    ) {}

    /**
     * @param  array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool|null, ignore_existing?: bool}  $options
     *
     * @throws ReviewTrialRefused
     */
    public function start(Tenant $tenant, string $url, array $options = []): Repository
    {
        $link = PullRequestLink::parse($url);

        if ($link === null) {
            throw new ReviewTrialRefused('That is not the address of a pull request on Bitbucket or GitHub, such as https://bitbucket.org/workspace/repository/pull-requests/12 or https://github.com/owner/repository/pull/12.');
        }

        $connection = $this->connection($tenant, $link);
        $repository = $this->repository($tenant, $connection, $link);

        // The runner asked for, else the repository's. A reviewer asked for runs on
        // a runner that has one: the diff has no reviewer to choose.
        $asked = filled($options['runner'] ?? null) ? (string) $options['runner'] : null;
        $own = $repository->runnerName();
        $runner = $asked ?? (filled($options['provider'] ?? null) && $own === DiffRunner::NAME ? null : $own);
        $fromDiff = $runner === DiffRunner::NAME;
        $container = $fromDiff ? null : $this->runners->containerFor($runner);

        if ($fromDiff && ! $this->diff->available()) {
            throw new ReviewTrialRefused('Reviewing from the diff alone is turned off on this installation.');
        }

        if ($container !== null && ! $container->available()) {
            throw new ReviewTrialRefused('Trying a pull request with a reviewer needs Docker or a Cloudflare sandbox, and neither is set up on this installation. "The diff alone" needs neither.');
        }

        try {
            $pullRequest = $repository->sourceControl()->getPullRequest((string) $link->id);
        } catch (Throwable $exception) {
            throw new ReviewTrialRefused("{$connection->name} could not read the pull request: {$exception->getMessage()}", previous: $exception);
        }

        if ($pullRequest === null) {
            throw new ReviewTrialRefused("{$connection->name} finds no pull request {$link->id} in {$link->workspace}/{$link->slug}.");
        }

        // Its branch is in another repository, which a clone of this one has not got.
        // GitHub keeps every pull request's head in the repository itself as well.
        $fork = ! $fromDiff && ($pullRequest['from_fork'] ?? false) === true && ($pullRequest['state'] ?? null) === 'OPEN';

        if ($fork && $link->host !== PullRequestLink::GITHUB) {
            throw new ReviewTrialRefused('This pull request comes from a fork, and it is still open: its branch is not in the repository to clone. Try it from the diff alone, or once it is merged.');
        }

        $branch = $pullRequest['source']['branch']['name'] ?? null;
        $destination = $pullRequest['destination']['branch']['name'] ?? null;

        if (! is_string($branch) || $branch === '' || ! is_string($destination) || $destination === '') {
            throw new ReviewTrialRefused('The pull request does not say which branches it is between.');
        }

        $trial = new Trial(
            pullRequestId: $link->id,
            destination: $destination,
            mergeCommit: $this->mergeCommit($pullRequest, $fromDiff),
            provider: $fromDiff ? null : ($options['provider'] ?? null),
            model: $options['model'] ?? null,
            effort: $options['effort'] ?? null,
            verify: $options['verify'] ?? null,
            ignoreExisting: $options['ignore_existing'] ?? true,
            sandbox: $container?->name(),
            pullRef: $fork ? "pull/{$link->id}/head" : null,
        );

        // Only now: a pull request that cannot be tried leaves no repository behind.
        $repository->save();
        $title = is_string($pullRequest['title'] ?? null) ? $pullRequest['title'] : null;
        $container === null
            ? $this->diff->startTrial($repository, $branch, $trial, $title)
            : $container->startTrial($repository, $branch, $trial, $title);

        return $repository;
    }

    /**
     * @param  array<string, mixed>  $pullRequest
     *
     * @throws ReviewTrialRefused
     */
    protected function mergeCommit(array $pullRequest, bool $fromDiff = false): ?string
    {
        $state = $pullRequest['state'] ?? null;

        if ($state === 'OPEN') {
            return null;
        }

        $merged = $pullRequest['merge_commit']['hash'] ?? null;

        if ($state !== 'MERGED' || ! is_string($merged) || $merged === '') {
            throw new ReviewTrialRefused('Only an open or a merged pull request can be tried: a declined one has no commit left to review.');
        }

        $head = $pullRequest['source']['commit']['hash'] ?? null;

        // Merged without a commit of its own, the last commit says nothing about
        // where the pull request began. The host still has the pull request's
        // diff, so a review from the diff alone needs no such commit.
        if (! $fromDiff && is_string($head) && $head !== '' && ReviewContext::isSameCommit($merged, $head)) {
            throw new ReviewTrialRefused('This pull request was merged by fast-forward, which leaves no merge commit to tell its changes from. It cannot be tried yet.');
        }

        // Rebased onto the branch, its commits went in one by one and the "merge"
        // is only the last of them. The diff on the host is still the whole.
        if (! $fromDiff && ($pullRequest['merge_commit']['rebased'] ?? false) === true) {
            throw new ReviewTrialRefused('This pull request was merged by rebase, so its commits went in one by one and no single commit holds its changes. Try it with "The diff alone".');
        }

        return $merged;
    }

    /**
     * The connection that reads the pull request: one of its host for its
     * workspace or owner. A public repository on GitHub needs none of the
     * tenant's own, so a connection for any of them is made when there is none.
     *
     * @throws ReviewTrialRefused
     */
    protected function connection(Tenant $tenant, PullRequestLink $link): Connection
    {
        $connections = $tenant->connections()
            ->where('kind', ConnectionKind::SourceControl)
            ->where('driver', $link->host)
            ->oldest('id')
            ->get();

        $connection = $connections->first(fn (Connection $connection): bool => strcasecmp((string) $connection->setting('workspace'), $link->workspace) === 0)
            // One for any public repository of the host, where the host has those.
            ?? ($link->host === PullRequestLink::GITHUB ? $connections->first(fn (Connection $connection): bool => $connection->setting('workspace') === null) : null);

        if ($connection === null && $link->host === PullRequestLink::GITHUB) {
            return $tenant->connections()->create([
                'kind' => ConnectionKind::SourceControl, 'driver' => PullRequestLink::GITHUB,
                'name' => 'GitHub (public repositories)', 'credentials' => [], 'settings' => [],
            ]);
        }

        if ($connection === null) {
            throw new ReviewTrialRefused("No source control connection here is for the workspace {$link->workspace}. Add one under Source control first.");
        }

        return $connection;
    }

    /**
     * The repository as it is set up here, or a new one that reviews nothing on
     * its own. Not saved yet.
     */
    protected function repository(Tenant $tenant, Connection $connection, PullRequestLink $link): Repository
    {
        $repository = $tenant->repositories()
            ->where('connection_id', $connection->getKey())
            // As it was saved: with the connection's own workspace, which may be
            // written otherwise than in the address.
            ->where('workspace', (string) ($connection->setting('workspace') ?? $link->workspace))
            ->where('slug', $link->slug)
            ->first();

        if ($repository !== null) {
            return $repository;
        }

        $repository = new Repository($this->setup->complete([
            'tenant_id' => $tenant->getKey(),
            'connection_id' => $connection->getKey(),
            'workspace' => $link->workspace,
            'slug' => $link->slug,
            'reviews_enabled' => false,
        ], $connection));

        return $repository->setRelation('integration', $connection)->setRelation('tenant', $tenant);
    }
}
