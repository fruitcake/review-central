<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An AI provider that was asked for a review and gave none that can be used: it
 * refused, ran out of room, answered with an error or with something that is no
 * report. The message is shown in the run's log.
 */
class ReviewerFailed extends RuntimeException {}
