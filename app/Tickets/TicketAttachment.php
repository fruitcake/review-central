<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * A file on a ticket, as the tracker lists it: a screenshot, a log, a document.
 */
final readonly class TicketAttachment
{
    /**
     * @param  string|null  $downloadUrl  where its contents are read, null for one that lives elsewhere, as a Dropbox link on an Asana task
     * @param  string|null  $viewUrl  where a person opens it
     */
    public function __construct(
        public string $name,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?string $downloadUrl = null,
        public ?string $viewUrl = null,
    ) {}

    /**
     * Its line in the ticket's list, by the number it is read by.
     */
    public function line(int $number): string
    {
        $details = array_filter([
            $this->mimeType,
            $this->size === null ? null : self::size($this->size),
            $this->downloadUrl === null ? 'not readable here'.($this->viewUrl === null ? '' : ": {$this->viewUrl}") : null,
        ]);

        return "{$number}. {$this->name}".($details === [] ? '' : ' ('.implode(', ', $details).')');
    }

    /**
     * The list a ticket's markdown ends with.
     *
     * @param  array<int, self>  $attachments
     * @return array<int, string>
     */
    public static function section(array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        $lines = ['', '## Attachments', ''];

        foreach (array_values($attachments) as $index => $attachment) {
            $lines[] = $attachment->line($index + 1);
        }

        return $lines;
    }

    protected static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => round($bytes / 1024 / 1024, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => "{$bytes} bytes",
        };
    }
}
