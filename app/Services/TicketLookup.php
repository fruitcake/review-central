<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\TicketSource;
use App\Tickets\TicketResolver;
use Throwable;

/**
 * The ticket a change is for, read from the repository's ticket sources the way
 * its review reads it, for whatever reads as the user outside the panel.
 *
 * A source reads with its connection's access, which can reach tickets a member
 * of the tenant could not open in the tracker. So a ticket's text is only given
 * from a source an admin made readable over MCP; from any other, only which
 * ticket it is.
 */
class TicketLookup
{
    public function __construct(protected TicketResolver $tickets) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ActionRefused when no source reads the reference, or the ticket cannot be read
     */
    public function byReference(Repository $repository, string $reference): array
    {
        return $this->found(
            $repository,
            $this->tickets->matchReference($repository, $reference),
            "No ticket source of {$repository->fullName()} reads \"{$reference}\". Its sources are set up in Review Central, under Tickets.",
        );
    }

    /**
     * The ticket the pull request's title names, as its review would read it now:
     * the title as the host has it, by the pull request's number or as the open
     * pull request of the branch.
     *
     * @return array<string, mixed>
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
                $this->tickets->matchTitle($repository, $title),
                "The title of pull request #{$id}, \"{$title}\", names no ticket a source of {$repository->fullName()} reads, so its review reads none either.",
            ),
            'pull_request_id' => $id,
            'title' => $title,
        ];
    }

    /**
     * The ticket a review read as its input, kept with the run: which ticket it
     * was, and its text when its source is readable over MCP now.
     *
     * @return array<string, string>|null null when the pull request's title named none
     */
    public function saved(ReviewRun $run): ?array
    {
        $ticket = $run->context['ticket'] ?? null;

        if (! is_array($ticket)) {
            return null;
        }

        $shown = array_filter(
            array_intersect_key($ticket, array_flip(['reference', 'source', 'url', 'error'])),
            fn (mixed $value): bool => is_string($value) && $value !== '',
        );
        $source = is_int($ticket['source_id'] ?? null)
            ? TicketSource::query()->where('tenant_id', $run->tenant_id)->find($ticket['source_id'])
            : null;

        if ($source?->readable_over_mcp && is_string($ticket['markdown'] ?? null)) {
            return $shown + ['markdown' => $ticket['markdown']];
        }

        return $shown + (isset($shown['error']) ? [] : ['notice' => self::notReadable($source->name ?? $shown['source'] ?? 'its ticket source')]);
    }

    /**
     * @param  array{0: TicketSource, 1: string}|null  $match
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    protected function found(Repository $repository, ?array $match, string $none): array
    {
        if ($match === null) {
            throw new ActionRefused($none);
        }

        [$source, $reference] = $match;
        $graded = ReviewSettings::gradesTicket($repository);

        // Not asked of the tracker at all: whether a ticket exists is something too.
        if (! $source->readable_over_mcp) {
            return ['reference' => $reference, 'source' => $source->name, 'graded' => $graded, 'notice' => self::notReadable($source->name)];
        }

        $ticket = $this->tickets->read($repository, $source, $reference);

        if ($ticket['error'] !== null) {
            throw new ActionRefused("{$ticket['reference']}: {$ticket['error']}");
        }

        return ['reference' => $ticket['reference'], 'source' => $ticket['source'], 'url' => $ticket['url'], 'markdown' => $ticket['markdown'], 'graded' => $graded];
    }

    protected static function notReadable(string $source): string
    {
        return "The ticket's text is not given here: {$source} is not readable over MCP. An admin of the tenant can turn that on for it in Review Central, under Tickets; until then, open the ticket in the tracker.";
    }
}
