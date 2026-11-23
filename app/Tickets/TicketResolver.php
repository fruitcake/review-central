<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Connections\TicketDriver;
use App\Enums\TicketStyle;
use App\Exceptions\TicketNotFound;
use App\Models\Repository;
use App\Models\TicketSource;
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
     * "#29386 - Implement AI pipeline", "feat #29249 show notes".
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
     * @return array{reference: string, source: string, source_id: int, url: string|null, markdown: string|null, error: string|null}|null
     */
    public function forTitle(Repository $repository, ?string $title): ?array
    {
        $match = $this->matchTitle($repository, $title);

        return $match === null ? null : $this->read($repository, ...$match);
    }

    /**
     * The source of this repository that reads a reference in the title, with the
     * reference, without reading the ticket.
     *
     * @return array{0: TicketSource, 1: string}|null
     */
    public function matchTitle(Repository $repository, ?string $title): ?array
    {
        return $this->match($this->sources($repository), $title);
    }

    /**
     * The same for a reference given by itself, as a title would carry it:
     * `PROJ-123`, `#1234` or `1234`.
     *
     * @return array{0: TicketSource, 1: string}|null
     */
    public function matchReference(Repository $repository, string $reference): ?array
    {
        $reference = trim($reference);

        return $this->matchTitle($repository, ctype_digit($reference) ? "#{$reference}" : $reference);
    }

    /**
     * The ticket from the source, kept with the run as the review's input. The
     * source's id says later where it came from.
     *
     * @return array{reference: string, source: string, source_id: int, url: string|null, markdown: string|null, error: string|null}
     */
    public function read(Repository $repository, TicketSource $source, string $reference): array
    {
        $ticket = ['reference' => $reference, 'source' => $source->name, 'source_id' => (int) $source->getKey(), 'url' => null, 'markdown' => null, 'error' => null];

        try {
            /** @var TicketDriver $driver */
            $driver = $source->integration->driver();
            $found = $driver->fetch($source->integration, $reference, $repository);

            $ticket['reference'] = $found->reference;
            $ticket['url'] = $found->url;
            $ticket['markdown'] = $found->markdown;
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
