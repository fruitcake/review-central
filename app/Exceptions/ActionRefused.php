<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Something a user asked for that they may not do, or that cannot be done now,
 * with why in words they can act on.
 */
class ActionRefused extends RuntimeException {}
