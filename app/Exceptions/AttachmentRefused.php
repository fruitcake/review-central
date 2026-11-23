<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A ticket's attachment that is not read here: kept elsewhere, too large, or not
 * on the ticket. The message says which, for the agent that asked.
 */
class AttachmentRefused extends RuntimeException {}
