<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of a review as a pull request shows it among its builds.
 */
enum BuildState: string
{
    case InProgress = 'in_progress';

    case Successful = 'successful';

    case Failed = 'failed';

    /** Ended without a verdict: it crashed, timed out or was turned away. */
    case Stopped = 'stopped';
}
