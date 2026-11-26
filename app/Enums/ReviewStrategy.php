<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a pull request is classified before its review, and whether what it is
 * classified as decides the effort and model the review runs at.
 */
enum ReviewStrategy: string
{
    case Off = 'off';

    /** Classified and shown; the review runs at what is set. */
    case Record = 'record';

    /** Classified, and the review runs at the effort and model that follow from it. */
    case Apply = 'apply';

    public function text(): string
    {
        return match ($this) {
            self::Off => __('Off'),
            self::Record => __('Classify only, and show what it would pick'),
            self::Apply => __('Classify, and run at what it picks'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(array_map(fn (self $case): array => [$case->value, $case->text()], self::cases()), 1, 0);
    }
}
