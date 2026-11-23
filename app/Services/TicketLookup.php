<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Exceptions\AttachmentRefused;
use App\Exceptions\RepositoryNotFound;
use App\Exceptions\TicketNotFound;
use App\Models\Repository;
use App\Tickets\AttachmentFile;
use App\Tickets\TicketResolver;
use Throwable;

/**
 * The ticket a change is for, read from the repository's ticket sources the way
 * its review reads it, for whatever reads as the user outside the panel.
 */
class TicketLookup
{
    public function __construct(protected TicketResolver $tickets) {}

    /**
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, graded: bool}
     *
     * @throws ActionRefused when no source reads the reference, or the ticket cannot be read
     */
    public function byReference(Repository $repository, string $reference): array
    {
        return $this->found(
            $repository,
            $this->tickets->forReference($repository, $reference),
            "No ticket source of {$repository->fullName()} reads \"{$reference}\". Its sources are set up in Review Central, under Tickets.",
        );
    }

    /**
     * An attachment of a ticket, by the number the ticket lists it under.
     *
     * @throws ActionRefused when it cannot be read
     */
    public function attachment(Repository $repository, string $reference, int $number): AttachmentFile
    {
        try {
            return $this->tickets->attachment($repository, $reference, $number);
        } catch (AttachmentRefused $exception) {
            throw new ActionRefused($exception->getMessage());
        } catch (TicketNotFound) {
            throw new ActionRefused("No ticket matches \"{$reference}\".");
        } catch (Throwable $exception) {
            report($exception);

            throw new ActionRefused("Attachment {$number} of {$reference} could not be read.");
        }
    }

    /**
     * The ticket the pull request's title names, as its review would read it now:
     * the title as the host has it, by the pull request's number or as the open
     * pull request of the branch.
     *
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, graded: bool, pull_request_id: int, title: string}
     *
     * @throws RepositoryNotFound
     * @throws ActionRefused
     */
    public function forPullRequest(Repository $repository, ?int $pullRequestId, ?string $branch = null): array
    {
        if ($pullRequestId === null && blank($branch)) {
            throw new RepositoryNotFound('Name the pull request: its number, or the branch it is from.');
        }

        try {
            $pullRequest = $pullRequestId !== null
                ? $repository->sourceControl()->getPullRequest((string) $pullRequestId)
                : $repository->sourceControl()->findOpenPullRequestByBranch((string) $branch);
        } catch (Throwable $exception) {
            throw new ActionRefused("The pull request could not be read from {$repository->integration->name}: {$exception->getMessage()}");
        }

        if ($pullRequest === null || ! is_numeric($pullRequest['id'] ?? null)) {
            throw new RepositoryNotFound($pullRequestId !== null
                ? "{$repository->fullName()} has no pull request #{$pullRequestId}."
                : "{$repository->fullName()} has no open pull request from {$branch}.");
        }

        $id = (int) $pullRequest['id'];
        $title = $pullRequest['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw new ActionRefused("Pull request #{$id} has no title to name a ticket.");
        }

        return [
            ...$this->found(
                $repository,
                $this->tickets->forTitle($repository, $title),
                "The title of pull request #{$id}, \"{$title}\", names no ticket a source of {$repository->fullName()} reads, so its review reads none either.",
            ),
            'pull_request_id' => $id,
            'title' => $title,
        ];
    }

    /**
     * @param  array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, error: string|null}|null  $ticket
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, graded: bool}
     *
     * @throws ActionRefused
     */
    protected function found(Repository $repository, ?array $ticket, string $none): array
    {
        if ($ticket === null) {
            throw new ActionRefused($none);
        }

        if ($ticket['error'] !== null) {
            throw new ActionRefused("{$ticket['reference']}: {$ticket['error']}");
        }

        return ['reference' => $ticket['reference'], 'source' => $ticket['source'], 'url' => $ticket['url'], 'markdown' => $ticket['markdown'], 'attachments' => $ticket['attachments'], 'graded' => ReviewSettings::gradesTicket($repository)];
    }
}
