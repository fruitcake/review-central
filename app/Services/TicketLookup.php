<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Exceptions\AttachmentRefused;
use App\Exceptions\RepositoryNotFound;
use App\Exceptions\TicketNotFound;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Tickets\AttachmentFile;
use App\Tickets\TicketResolver;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The ticket a change is for, read from the repository's ticket sources the way
 * its review reads it, for whatever reads as the user outside the panel.
 */
class TicketLookup
{
    /** How long a ticket read for a pull request's details is kept. */
    public const DETAILS_DAYS = 7;

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
     * The ticket a pull request's details show: as it was last read from there,
     * else as its newest review read it, else only the reference its title names,
     * which is not read until someone asks.
     *
     * @param  array<string, mixed>|null  $reviewed  the ticket the newest review read
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, error: string|null, read_at: string|null}|null
     */
    public function forDetails(OpenPullRequest $record, ?array $reviewed): ?array
    {
        $named = $this->tickets->referenceIn($record->repository, $record->title);
        $read = Cache::get(self::detailsKey($record));

        // Only while the title still names that ticket: a renamed pull request may be for another.
        if (is_array($read) && filled($read['reference'] ?? null) && $named !== null && ($read['named'] ?? null) === $named['reference']) {
            return self::shown($read, is_string($read['read_at'] ?? null) ? $read['read_at'] : null);
        }

        if ($reviewed !== null && filled($reviewed['reference'] ?? null)) {
            return self::shown($reviewed, null);
        }

        return $named === null ? null : [...$named, 'url' => null, 'markdown' => null, 'error' => null, 'read_at' => null];
    }

    /**
     * Reads the ticket the pull request's title names from its source now, and
     * keeps it for its details.
     *
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, error: string|null, read_at: string|null}|null null when the title names no ticket a source reads
     */
    public function readForDetails(OpenPullRequest $record): ?array
    {
        $named = $this->tickets->referenceIn($record->repository, $record->title);
        $ticket = $named === null ? null : $this->tickets->forTitle($record->repository, $record->title);

        if ($named === null || $ticket === null) {
            Cache::forget(self::detailsKey($record));

            return null;
        }

        $read = [
            'reference' => $ticket['reference'],
            'source' => $ticket['source'],
            'url' => $ticket['url'],
            'markdown' => $ticket['markdown'],
            'error' => $ticket['error'],
            'read_at' => now()->toIso8601String(),
        ];

        // With the reference the title named, which the details hold it against.
        Cache::put(self::detailsKey($record), [...$read, 'named' => $named['reference']], now()->addDays(self::DETAILS_DAYS));

        return $read;
    }

    /**
     * @param  array<mixed>  $ticket  as it was kept, by a review or for the details
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, error: string|null, read_at: string|null}
     */
    protected static function shown(array $ticket, ?string $readAt): array
    {
        $text = fn (string $key): ?string => is_string($ticket[$key] ?? null) ? $ticket[$key] : null;

        return [
            'reference' => (string) $text('reference'),
            'source' => (string) $text('source'),
            'url' => $text('url'),
            'markdown' => $text('markdown'),
            'error' => $text('error'),
            'read_at' => $readAt,
        ];
    }

    protected static function detailsKey(OpenPullRequest $record): string
    {
        return "pull-request-ticket:{$record->repository_id}:{$record->pull_request_id}";
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
