<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\Tenant;
use App\Services\PlatformSettings;

/**
 * The colours a page wears: the default teal, the installation's colour over it,
 * and the open tenant's own over that.
 *
 * There is one look: the type, the corners and the layout are the same for
 * everyone. A tenant may set three colours of its own, and the shades that go with
 * them (a hover, a tint, a shade dark enough to read as text, and whether black or
 * white goes on top) are worked out here, so that a client's colours stay readable
 * without anyone choosing twenty of them.
 */
class Themes
{
    /** A colour as a tenant gives it. It is written into a stylesheet, so nothing else is taken. */
    public const COLOUR = '/^#[0-9a-fA-F]{6}$/';

    /** The least contrast text needs against what it is on. */
    protected const READABLE = 4.5;

    /**
     * The colours a tenant can set, with what each is used for.
     *
     * @var array<string, string>
     */
    public const BASES = [
        'ink' => 'The sidebar, the text and the other dark surfaces.',
        'accent' => 'The active menu item, the brand mark and the marks that say something is fine.',
        'button' => 'The main button. Empty follows the accent.',
    ];

    /**
     * The default: deep teal ink, a teal accent with white on it, on slate greys.
     * Chosen by hand, so they are used as they are and not worked out.
     *
     * @var array<string, string>
     */
    public const DEFAULT = [
        'ink' => '#0f172a',
        'ink-alt' => '#0b3b39',
        'ink-soft' => '#334155',
        'sidebar' => '#042f2e',
        // The admin panel's sidebar is darker, to tell it from a tenant.
        'sidebar-admin' => '#021c1b',
        'accent' => '#0f766e',
        'accent-dark' => '#0d6861',
        'accent-text' => '#0f766e',
        'accent-tint' => '#e7f1f0',
        'on-accent' => '#ffffff',
        'approved-tint' => '#ccfbf1',
        'button' => '#0f766e',
        'button-hover' => '#0d6861',
        'button-text' => '#ffffff',
        'danger' => '#dc2626',
        'danger-text' => '#b91c1c',
        'danger-tint' => '#fee2e2',
        'on-danger' => '#ffffff',
        'ground' => '#f8fafc',
        'soft' => '#f1f5f9',
        'border' => '#e2e8f0',
        'text' => '#0f172a',
        'muted' => '#556070',
        'grey' => '#94a3b8',
    ];

    /**
     * The colour variables of the stylesheet, for a tenant or for none.
     *
     * @return array<string, string>
     */
    public static function for(?Tenant $tenant): array
    {
        $colours = self::DEFAULT;
        $own = array_filter(
            array_intersect_key((array) ($tenant->theme_colors ?? []), self::BASES),
            fn (mixed $colour): bool => is_string($colour) && self::valid($colour),
        );

        // The installation's colour, under what a tenant set itself: the accent, and
        // the sidebars in a deep shade of it.
        $platform = app(PlatformSettings::class)->colour();

        if ($platform !== null) {
            $own += ['accent' => $platform];

            $colours = [
                'sidebar' => self::mix($platform, '#000000', 0.7),
                'sidebar-admin' => self::mix($platform, '#000000', 0.82),
                'ink-alt' => self::mix($platform, '#000000', 0.5),
            ] + $colours;
        }

        if (isset($own['ink'])) {
            $ink = strtolower($own['ink']);

            $colours = [
                'ink' => $ink,
                'ink-alt' => self::mix($ink, '#ffffff', 0.08),
                'ink-soft' => self::mix($ink, '#ffffff', 0.25),
                'sidebar' => $ink,
                // Text is the ink, unless the ink is too light to be read.
                'text' => self::readable($ink, '#ffffff'),
            ] + $colours;
        }

        if (isset($own['accent'])) {
            $accent = strtolower($own['accent']);

            $colours = [
                'accent' => $accent,
                'accent-dark' => self::mix($accent, '#000000', 0.12),
                'accent-text' => self::readable($accent, '#ffffff'),
                'accent-tint' => self::mix($accent, '#ffffff', 0.84),
                'approved-tint' => self::mix($accent, '#ffffff', 0.74),
                'on-accent' => self::on($accent, $colours['ink']),
            ] + $colours;
        }

        // The main button follows the accent until it is given a colour of its own.
        $button = strtolower($own['button'] ?? $own['accent'] ?? '');

        if ($button !== '') {
            $colours = [
                'button' => $button,
                'button-hover' => self::mix($button, '#000000', 0.12),
                'button-text' => self::on($button, $colours['ink']),
            ] + $colours;
        }

        // In the order they are listed above, whatever was replaced.
        return array_replace(self::DEFAULT, $colours);
    }

    public static function valid(string $colour): bool
    {
        return preg_match(self::COLOUR, $colour) === 1;
    }

    /**
     * What goes on top of a fill: the ink where that reads better, white where it does not.
     */
    protected static function on(string $fill, string $ink): string
    {
        return self::contrast($fill, $ink) >= self::contrast($fill, '#ffffff') ? $ink : '#ffffff';
    }

    /**
     * The colour itself when it can be read on the ground, and otherwise darkened
     * until it can.
     */
    protected static function readable(string $colour, string $ground): string
    {
        for ($black = 0.0; $black < 1.0 && self::contrast($colour, $ground) < self::READABLE; $black += 0.05) {
            $colour = self::mix($colour, '#000000', 0.05 / (1 - $black));
        }

        return $colour;
    }

    /**
     * A colour with a share of another mixed in.
     */
    protected static function mix(string $colour, string $with, float $share): string
    {
        $share = max(0.0, min(1.0, $share));
        [$a, $b] = [self::channels($colour), self::channels($with)];

        return sprintf('#%02x%02x%02x', ...array_map(
            fn (int $index): int => (int) round($a[$index] * (1 - $share) + $b[$index] * $share),
            [0, 1, 2],
        ));
    }

    /**
     * How well two colours read against each other, from 1 to 21 (WCAG).
     */
    protected static function contrast(string $one, string $other): float
    {
        [$light, $dark] = [max(self::luminance($one), self::luminance($other)), min(self::luminance($one), self::luminance($other))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    protected static function luminance(string $colour): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($colour));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected static function channels(string $colour): array
    {
        return [(int) hexdec(substr($colour, 1, 2)), (int) hexdec(substr($colour, 3, 2)), (int) hexdec(substr($colour, 5, 2))];
    }
}
