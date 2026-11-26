<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reasoning effort a review runs at. xhigh is the highest the review takes:
 * Codex has no max, and Claude Code ignores max in a settings file.
 */
enum ReviewEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';

    public function text(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Xhigh => __('Extra high'),
        };
    }
}
