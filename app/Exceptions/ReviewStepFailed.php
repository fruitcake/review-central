<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ReviewStepFailed extends RuntimeException
{
    /**
     * Without the step's output: it may quote the repository's code, and the log
     * has its end already.
     */
    public static function withExitCode(string $step, ?int $exitCode): self
    {
        return new self("The {$step} step of the review failed with exit code {$exitCode}; the application's log has the end of its output.");
    }
}
