<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why someone dismissed a finding: what the statistics break the dismissals down
 * by, so a team sees where its reviewer goes wrong, not only how often.
 */
enum FindingVerdictReason: string
{
    case Nitpick = 'nitpick';
    case OutOfScope = 'out_of_scope';
    case Intended = 'intended';
    case HandledElsewhere = 'handled_elsewhere';
    case Misread = 'misread';
    case Duplicate = 'duplicate';
    case Other = 'other';

    public function text(): string
    {
        return match ($this) {
            self::Nitpick => 'Nitpick',
            self::OutOfScope => 'Out of scope',
            self::Intended => 'Intended',
            self::HandledElsewhere => 'Handled elsewhere',
            self::Misread => 'Misread the code',
            self::Duplicate => 'Duplicate',
            self::Other => 'Other',
        };
    }

    /**
     * What it means, for whoever picks one without the page in front of them.
     */
    public function description(): string
    {
        return match ($this) {
            self::Nitpick => 'true, but too small to be worth a change',
            self::OutOfScope => 'true, but not for this change',
            self::Intended => 'the behaviour or trade-off is deliberate',
            self::HandledElsewhere => 'a guard, caller or test the reviewer did not see takes care of it',
            self::Misread => 'the reviewer misread the code or what the change is for',
            self::Duplicate => 'the same as another finding',
            self::Other => 'none of these; say why in the note',
        };
    }
}
