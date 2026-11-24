<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\TicketDriver;
use App\Enums\TicketStyle;
use App\Exceptions\AttachmentRefused;
use App\Exceptions\TicketNotFound;
use App\Models\Repository;
use App\Models\TicketSource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Finds the ticket a pull request title names, in the tenant's ticket sources.
 *
 * Only the title, never the description: a description often mentions a ticket it
 * does not implement, while a title names the work itself.
 */
class TicketResolver
{
    /**
     * `PREFIX-123`. The number ends the reference, so "Merged in PROJ-80-backups"
     * names PROJ-80; only a further digit is rejected, so PROJ-8 never matches PROJ-80.
     */
    public const KEY_REFERENCE = '/(?<![\w-])(?<prefix>[A-Za-z][A-Za-z0-9]+)-(?<number>\d+)(?!\d)/';

    /**
     * `#1234` at the start of the title, or after the kind of change:
     * "#4321 - Add the order export", "feat #8765 add a filter".
     */
    public const LEADING_NUMBER = '/^\s*(?:[A-Za-z]+(?:\([^)]*\))?!?:?\s+)?#(?<number>\d+)(?!\d)/';

    /**
     * `#1234` anywhere else: "Fix the export (#1234)".
     */
    public const NUMBER_REFERENCE = '/(?<![\w#])#(?<number>\d+)(?!\d)/';

    /**
     * The number in the title a host writes itself, "(pull request #123)", is the
     * pull request's own.
     */
    protected const PULL_REQUEST_NUMBER = '/\(?pull request #\d+\)?/i';

    /**
     * Words that look like a ticket prefix but are not, so "Fix UTF-8 handling"
     * does not cost a lookup when a source takes any prefix.
     *
     * @var array<int, string>
     */
    public const NOT_A_TICKET = ['UTF', 'ISO', 'SHA', 'RFC', 'PHP', 'ES', 'HTTP', 'IPV', 'BASE', 'MD', 'X'];

    /**
     * The ticket the title names, for the review's context, or null when it names
     * none a source of this repository reads.
     *
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, error: string|null}|null
     */
    public function forTitle(Repository $repository, ?string $title): ?array
    {
        $match = $this->match($this->sources($repository), $title);

        return $match === null ? null : $this->read($repository, ...$match);
    }

    /**
     * The ticket a reference names, as a title would carry it: `PROJ-123`, `#1234`
     * or `1234`. Null when no source of this repository reads it.
     *
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, error: string|null}|null
     */
    public function forReference(Repository $repository, string $reference): ?array
    {
        $match = $this->matchReference($this->sources($repository), $reference);

        return $match === null ? null : $this->read($repository, ...$match);
    }

    /**
     * The source a reference is for: as a title would carry it, or as a ticket
     * that was read gave it back, with the prefix a numbered source adds
     * (`SHOP-4321` for `#4321`).
     *
     * @param  Collection<int, TicketSource>  $sources
     * @return array{0: TicketSource, 1: string}|null
     */
    public function matchReference(Collection $sources, string $reference): ?array
    {
        $reference = trim($reference);

        foreach ($sources as $source) {
            $prefix = (string) $source->reference_prefix;

            if ($source->style === TicketStyle::Number && $prefix !== '' && stripos($reference, $prefix) === 0 && ctype_digit(substr($reference, strlen($prefix)))) {
                return [$source, $prefix.substr($reference, strlen($prefix))];
            }
        }

        return $this->match($sources, ctype_digit($reference) ? "#{$reference}" : $reference);
    }

    /**
     * The contents of a ticket's attachment, by the number its list gives it. The
     * ticket is read again, so the attachment is one the source reads now, and a
     * signed address to it is fresh.
     *
     * @throws AttachmentRefused when no source reads the reference, or it has no such attachment, or the attachment is not read here
     * @throws TicketNotFound
     * @throws RequestException
     */
    public function attachment(Repository $repository, string $reference, int $number): AttachmentFile
    {
        [$source, $reference] = $this->matchReference($this->sources($repository), $reference)
            ?? throw new AttachmentRefused("No ticket source of {$repository->fullName()} reads \"{$reference}\".");

        /** @var TicketDriver $driver */
        $driver = $source->integration->driver();
        $ticket = $driver->fetch($source->integration, $reference, $repository);
        $attachment = $ticket->attachments[$number - 1]
            ?? throw new AttachmentRefused("{$ticket->reference} has no attachment {$number}: it has ".count($ticket->attachments).'.');

        return $driver->download($source->integration, $attachment);
    }

    /**
     * @return array{reference: string, source: string, url: string|null, markdown: string|null, attachments: array<int, array{number: int, name: string, type: string|null, size: int|null, readable: bool}>, error: string|null}
     */
    protected function read(Repository $repository, TicketSource $source, string $reference): array
    {
        $ticket = ['reference' => $reference, 'source' => $source->name, 'url' => null, 'markdown' => null, 'attachments' => [], 'error' => null];

        try {
            /** @var TicketDriver $driver */
            $driver = $source->integration->driver();
            $found = $driver->fetch($source->integration, $reference, $repository);

            $ticket['reference'] = $found->reference;
            $ticket['url'] = $found->url;
            $ticket['markdown'] = $found->markdown;
            $ticket['attachments'] = array_map(fn (TicketAttachment $attachment, int $index): array => [
                'number' => $index + 1,
                'name' => $attachment->name,
                'type' => $attachment->mimeType,
                'size' => $attachment->size,
                'readable' => $attachment->downloadUrl !== null,
            ], $found->attachments, array_keys($found->attachments));
        } catch (TicketNotFound) {
            $ticket['error'] = "No ticket in {$source->name} matches this reference.";
        } catch (Throwable $exception) {
            report($exception);

            $ticket['error'] = "The ticket could not be read from {$source->name}.";
        }

        return $ticket;
    }

    /**
     * The first source that reads a reference in the title, with that reference
     * as its connection takes it.
     *
     * @param  Collection<int, TicketSource>  $sources  in the order they are tried
     * @return array{0: TicketSource, 1: string}|null
     */
    public function match(Collection $sources, ?string $title): ?array
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        foreach ($sources as $source) {
            $reference = match ($source->style) {
                TicketStyle::Key => $this->keyIn($title, $source->prefixes ?? []),
                TicketStyle::Number => $this->numberIn($title),
            };

            if ($reference !== null) {
                return [$source, ($source->reference_prefix ?? '').$reference];
            }
        }

        return null;
    }

    /**
     * A repository's own sources before the tenant's, each in its set order.
     *
     * @return Collection<int, TicketSource>
     */
    protected function sources(Repository $repository): Collection
    {
        return TicketSource::query()
            ->with('integration')
            ->where('tenant_id', $repository->tenant_id)
            ->where(fn ($query) => $query->whereNull('repository_id')->orWhere('repository_id', $repository->getKey()))
            ->orderByRaw('repository_id is null')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, string>  $prefixes  the prefixes that count; none means any
     */
    protected function keyIn(string $title, array $prefixes): ?string
    {
        $prefixes = array_map('strtoupper', $prefixes);

        if (! preg_match_all(self::KEY_REFERENCE, $title, $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            $prefix = strtoupper($match['prefix']);

            if ($prefixes === [] ? ! in_array($prefix, self::NOT_A_TICKET, true) : in_array($prefix, $prefixes, true)) {
                return "{$prefix}-{$match['number']}";
            }
        }

        return null;
    }

    protected function numberIn(string $title): ?string
    {
        if (preg_match(self::LEADING_NUMBER, $title, $matches)) {
            return $matches['number'];
        }

        $title = (string) preg_replace(self::PULL_REQUEST_NUMBER, '', $title);

        return preg_match(self::NUMBER_REFERENCE, $title, $matches) ? $matches['number'] : null;
    }
}
