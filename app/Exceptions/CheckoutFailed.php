<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CheckoutFailed extends RuntimeException
{
    public static function during(string $step, string $output): self
    {
        return new self("The repository could not be checked out ({$step}): ".trim(mb_substr($output, -500)));
    }

    public static function unsafeName(string $name): self
    {
        return new self("\"{$name}\" is not a branch or commit name this runner checks out.");
    }
}
