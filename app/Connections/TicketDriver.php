<?php

declare(strict_types=1);

namespace App\Connections;

use App\Exceptions\AttachmentRefused;
use App\Exceptions\TicketNotFound;
use App\Models\Connection;
use App\Models\Repository;
use App\Tickets\AttachmentFile;
use App\Tickets\Ticket;
use App\Tickets\TicketAttachment;
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

    /**
     * Reads an attachment of a ticket this just fetched, at most
     * AttachmentFile::MAX_BYTES of it.
     *
     * @throws RequestException
     * @throws AttachmentRefused when it cannot be read here, or is too large
     */
    public function download(Connection $connection, TicketAttachment $attachment): AttachmentFile;
}
