<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ConnectionKind;
use RuntimeException;

class UnknownDriver extends RuntimeException
{
    public static function named(ConnectionKind $kind, string $driver): self
    {
        return new self("No {$kind->value} driver is registered as \"{$driver}\".");
    }
}
