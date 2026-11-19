<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may start a review by commenting `/review` on a pull request. A review still
 * starts on every push, and from the panel, whatever this says.
 */
enum ReviewCommandAccess: string
{
    /** Anyone who can comment on the pull request. */
    case Anyone = 'anyone';

    /** Only the accounts the tenant listed. */
    case Listed = 'listed';

    /** Nobody: the command is ignored. */
    case Nobody = 'nobody';

    public function label(): string
    {
        return match ($this) {
            self::Anyone => 'Anyone who can comment on the pull request',
            self::Listed => 'Only the accounts listed below',
            self::Nobody => 'Nobody: reviews start on a push, or from here',
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
