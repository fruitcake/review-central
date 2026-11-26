<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The language a user reads the panels in, as they chose on their profile.
 */
enum Language: string
{
    case English = 'en';

    case Dutch = 'nl';

    /** In its own language, for whoever is looking for it. */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Dutch => 'Nederlands',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
