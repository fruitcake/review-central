<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whose pull requests a repository reviews by itself, on a push. The others are
 * listed under Pull requests, for someone in the team to start.
 */
enum ReviewsFrom: string
{
    /** Every pull request. */
    case Everyone = 'everyone';

    /** Only the pull requests of the team's trusted accounts. */
    case Trusted = 'trusted';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Every pull request',
            self::Trusted => 'Only those of the trusted accounts; the others wait for someone to start them',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
