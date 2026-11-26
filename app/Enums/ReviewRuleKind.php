<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a team's review rule asks of the reviewer.
 */
enum ReviewRuleKind: string
{
    /** Report a change that breaks it. */
    case Check = 'check';

    /** A pattern that is not a defect here: do not report it. */
    case Convention = 'convention';

    public function text(): string
    {
        return match ($this) {
            self::Check => __('Check'),
            self::Convention => __('Not a defect here'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Check => __('The reviewer reports a change that breaks it.'),
            self::Convention => __('The reviewer does not report it: it was judged a false positive, or it is how this team works.'),
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
