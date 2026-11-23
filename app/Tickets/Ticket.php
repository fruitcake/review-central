<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * A ticket as the review reads it, with the files on it, which its markdown lists.
 */
final readonly class Ticket
{
    /**
     * @param  array<int, TicketAttachment>  $attachments  in the order the markdown numbers them
     */
    public function __construct(
        public string $reference,
        public string $markdown,
        public ?string $url = null,
        public array $attachments = [],
    ) {}
}
