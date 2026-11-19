<?php

declare(strict_types=1);

namespace App\Enums;

enum FindingVerdictKind: string
{
    case Valid = 'valid';
    case NotAProblem = 'not_a_problem';
    case Wrong = 'wrong';

    public function text(): string
    {
        return match ($this) {
            self::Valid => 'Valid, will fix',
            self::NotAProblem => 'Not a problem',
            self::Wrong => 'Factually wrong',
        };
    }

    /**
     * Whether the finding was worth reporting. Only that counts as accepted.
     */
    public function accepts(): bool
    {
        return $this === self::Valid;
    }
}
