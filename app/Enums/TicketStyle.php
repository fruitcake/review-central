<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a pull request title names its ticket.
 */
enum TicketStyle: string
{
    /** `PREFIX-123`, as Jira and Asana custom IDs write it. */
    case Key = 'key';

    /** `#1234`, as GitHub writes it. */
    case Number = 'number';

    public function text(): string
    {
        return match ($this) {
            self::Key => 'PREFIX-123',
            self::Number => '#1234',
        };
    }
}
