<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Models\Repository;
use App\Tickets\TicketResolver;

/**
 * The ticket a change is for, read from the repository's ticket sources the way
 * its review reads it, for whatever reads as the user outside the panel.
 */
class TicketLookup
{
    public function __construct(protected TicketResolver $tickets, protected PullRequestFeedback $feedback) {}

    /**
     * @return array{reference: string, source: string, url: string|null, markdown: string|null}
     *
     * @throws ActionRefused when no source reads the reference, or the ticket cannot be read
     */
    public function byReference(Repository $repository, string $reference): array
    {
        return $this->found(
            $this->tickets->forReference($repository, $reference),
            "No ticket source of {$repository->fullName()} reads \"{$reference}\". Its sources are set up in Review Central, under Tickets.",
        );
    }

    /**
     * The ticket the pull request's title names, as its review would read it now.
     *
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, pull_request_id: int, title: string}
     *
     * @throws RepositoryNotFound
     * @throws ActionRefused
     */
    public function forPullRequest(Repository $repository, ?int $pullRequestId, ?string $branch = null): array
    {
        $pullRequest = $this->feedback->resolve($repository, $pullRequestId, $branch);
        $title = $pullRequest['record']->title
            ?? rescue(fn () => $repository->sourceControl()->getPullRequest((string) $pullRequest['id'])['title'] ?? null, null, false);

        if (! is_string($title) || trim($title) === '') {
            throw new ActionRefused("The title of pull request #{$pullRequest['id']} could not be read.");
        }

        return [
            ...$this->found(
                $this->tickets->forTitle($repository, $title),
                "The title of pull request #{$pullRequest['id']}, \"{$title}\", names no ticket a source of {$repository->fullName()} reads, so its review reads none either.",
            ),
            'pull_request_id' => $pullRequest['id'],
            'title' => $title,
        ];
    }

    /**
     * @param  array{reference: string, source: string, url: string|null, markdown: string|null, error: string|null}|null  $ticket
     * @return array{reference: string, source: string, url: string|null, markdown: string|null}
     *
     * @throws ActionRefused
     */
    protected function found(?array $ticket, string $none): array
    {
        if ($ticket === null) {
            throw new ActionRefused($none);
        }

        if ($ticket['error'] !== null) {
            throw new ActionRefused("{$ticket['reference']}: {$ticket['error']}");
        }

        return ['reference' => $ticket['reference'], 'source' => $ticket['source'], 'url' => $ticket['url'], 'markdown' => $ticket['markdown']];
    }
}
