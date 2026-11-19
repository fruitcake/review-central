<?php

declare(strict_types=1);

namespace App\Connections;

use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use App\Tickets\Ticket;
use Illuminate\Http\Client\RequestException;

/**
 * A place tickets live, read for the context of a review.
 */
interface TicketDriver extends ConnectionDriver
{
    /**
     * Reads a ticket by the reference a pull request title names: `PROJ-123`, or
     * `1234` for a `#1234`. The repository is the one under review, for a tracker
     * that numbers its tickets per repository.
     *
     * @throws TicketNotFound
     * @throws RequestException
     */
    public function fetch(Connection $connection, string $reference, Repository $repository): Ticket;
}
