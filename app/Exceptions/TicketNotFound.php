<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class TicketNotFound extends RuntimeException
{
    public static function forReference(string $reference): self
    {
        return new self("No ticket matches {$reference}.");
    }
}
