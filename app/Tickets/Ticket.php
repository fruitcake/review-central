<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * A ticket as the review reads it.
 */
final readonly class Ticket
{
    public function __construct(
        public string $reference,
        public string $markdown,
        public ?string $url = null,
    ) {}
}
