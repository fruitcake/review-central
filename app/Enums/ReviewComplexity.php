<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much a pull request asks of its review, relative to the team's minimum: a
 * simple one runs at the minimum, a normal one a step above, a complex one two.
 */
enum ReviewComplexity: string
{
    case Simple = 'simple';
    case Normal = 'normal';
    case Complex = 'complex';

    public function text(): string
    {
        return ucfirst($this->value);
    }

    /** The steps above the minimum it runs at. */
    public function steps(): int
    {
        return match ($this) {
            self::Simple => 0,
            self::Normal => 1,
            self::Complex => 2,
        };
    }
}
