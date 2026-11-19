<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A pull request that cannot be tried, with the reason as someone can act on it.
 */
class ReviewTrialRefused extends RuntimeException {}
