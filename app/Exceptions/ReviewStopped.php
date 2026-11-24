<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone stopped the review while its reviewer ran: the sandbox was stopped with it.
 */
class ReviewStopped extends RuntimeException {}
