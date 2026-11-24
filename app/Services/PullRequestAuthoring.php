<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\Client\RequestException;

/**
 * Opens a pull request as the user themselves, with their own Bitbucket or
 * GitHub account, and later rewrites its title and description when the change
 * moves on: what a coding agent does for them, under their name.
 *
 * Only their own pull requests are rewritten, as the host names their author:
 * someone else's words are not the agent's to replace.
 */
class PullRequestAuthoring
{
    public function __construct(protected PersonalConnections $connections) {}

    /**
     * @return array{id: int, url: string}
     *
     * @throws ActionRefused when the user cannot open it as themselves, the branch has one open already, or the host refuses it
     */
    public function create(User $user, Repository $repository, string $branch, ?string $destination, string $title, string $description, bool $draft): array
    {
        ['client' => $host] = $this->connections->authorFor($user, $repository);

        $open = $host->findOpenPullRequestByBranch($branch);

        if ($open !== null) {
            throw new ActionRefused("{$branch} already has an open pull request: {$host->pullRequestUrl((int) $open['id'])}. Use `update-pull-request` to rewrite it.");
        }

        try {
            return $host->openPullRequest($branch, $destination, $title, ReviewContext::authorDescription($description), $draft);
        } catch (RequestException $exception) {
            throw new ActionRefused("{$repository->fullName()} refused the pull request: ".$this->reason($exception));
        }
    }

    /**
     * Rewrites the title, unless none is given, and the description, keeping the
     * review's own section under it as it is.
     *
     * @return array{id: int, url: string}
     *
     * @throws ActionRefused when there is no such pull request, it is someone else's, or the host refuses the change
     */
    public function update(User $user, Repository $repository, ?int $pullRequestId, ?string $branch, ?string $title, string $description): array
    {
        ['client' => $host, 'account_id' => $accountId] = $this->connections->authorFor($user, $repository);

        $current = $pullRequestId !== null ? $host->getPullRequest((string) $pullRequestId) : $host->findOpenPullRequestByBranch((string) $branch);

        if ($current === null) {
            throw new ActionRefused($pullRequestId !== null
                ? "{$repository->fullName()} has no pull request #{$pullRequestId} that your account can see."
                : "{$branch} has no open pull request in {$repository->fullName()}.");
        }

        $id = (int) $current['id'];

        if ((string) ($current['author']['account_id'] ?? '') !== $accountId) {
            throw new ActionRefused("Pull request #{$id} is not yours: only the title and description of a pull request you opened are rewritten. Suggest the change to its author instead.");
        }

        $status = $host->updatePullRequestDescription((string) $id, $title ?? (string) ($current['title'] ?? ''), $this->keepingReview($description, (string) ($current['description'] ?? '')));

        if ($status < 200 || $status >= 300) {
            throw new ActionRefused("{$repository->fullName()} refused the change to #{$id} ({$status}).");
        }

        return ['id' => $id, 'url' => $host->pullRequestUrl($id)];
    }

    /**
     * The new description with the review's section of the current one under it,
     * where the review keeps it. A copy of that section in the new text is left
     * out, so it is never there twice or stale.
     */
    protected function keepingReview(string $description, string $current): string
    {
        $description = ReviewContext::authorDescription($description);

        if (! preg_match(ReviewContext::DESCRIPTION_BLOCK, $current, $matches)) {
            return $description;
        }

        return app(ReviewPublisher::class)->spliceBlock($description, trim($matches[0]));
    }

    protected function reason(RequestException $exception): string
    {
        $message = $exception->response->json('error.message') ?? $exception->response->json('errors.0.message') ?? $exception->response->json('message');

        return is_string($message) && $message !== '' ? $message : "it answered {$exception->response->status()}";
    }
}
