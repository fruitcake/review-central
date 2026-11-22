<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A repository, pull request or review someone named that is not among theirs:
 * not there, or in a tenant they are not in, which reads the same.
 */
class RepositoryNotFound extends RuntimeException {}
