<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Themes;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * What holds for the whole installation, set by an administrator under Settings:
 * the currency and the time zone the admin panel shows its figures and times in,
 * and that a new tenant starts with (a tenant reads its own pages in its own),
 * how a repository's reviews run when it picks nothing, and how the installation
 * looks: its name, its logo, its colour and whether it credits its makers.
 *
 * Read on every page, so kept in the cache until they are saved again.
 */
class PlatformSettings
{
    public const CURRENCY = 'currency';

    public const TIMEZONE = 'timezone';

    /** How a repository's reviews run when it picks nothing: docker, cloudflare or diff. */
    public const RUNNER = 'runner';

    /** The name in the sidebar, the title of every page and the mail. */
    public const APP_NAME = 'app_name';

    /** A logo in place of the mark, kept as a data URI so nothing has to be served for it. */
    public const LOGO = 'logo';

    /** The accent of every panel, under a tenant's own. Not set: the default teal. */
    public const COLOUR = 'colour';

    /** Whether the sidebar says who made Review Central. */
    public const CREDIT = 'credit';

    public const DEFAULT_NAME = 'Review Central';

    /** A logo is an image, of a size the settings table holds without trouble. */
    public const LOGO_KILOBYTES = 200;

    /** What holds while nobody has set anything. */
    public const DEFAULTS = [
        self::CURRENCY => Currency::USD,
        self::TIMEZONE => 'UTC',
        // Not set: the configured default (REVIEW_DEFAULT_RUNNER) holds.
        self::RUNNER => null,
        self::APP_NAME => null,
        self::LOGO => null,
        self::COLOUR => null,
        self::CREDIT => true,
    ];

    protected const CACHE = 'platform-settings';

    /** @var array<string, mixed>|null */
    protected ?array $values = null;

    public function currency(): string
    {
        $currency = $this->get(self::CURRENCY);

        return is_string($currency) && isset(Currency::SYMBOLS[$currency]) ? $currency : Currency::USD;
    }

    public function timezone(): string
    {
        $zone = $this->get(self::TIMEZONE);

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : self::DEFAULTS[self::TIMEZONE];
    }

    public function runner(): ?string
    {
        $runner = $this->get(self::RUNNER);

        return is_string($runner) && $runner !== '' ? $runner : null;
    }

    public function appName(): string
    {
        $name = $this->get(self::APP_NAME);

        return is_string($name) && trim($name) !== '' ? trim($name) : self::DEFAULT_NAME;
    }

    /**
     * The uploaded logo as a data URI, or null for the mark. Only a PNG or an SVG,
     * shown in an image tag, where an SVG runs no script.
     */
    public function logo(): ?string
    {
        $logo = $this->get(self::LOGO);

        return is_string($logo) && preg_match('#^data:image/(png|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $logo) === 1 ? $logo : null;
    }

    public function colour(): ?string
    {
        $colour = $this->get(self::COLOUR);

        return is_string($colour) && Themes::valid($colour) ? strtolower($colour) : null;
    }

    public function credit(): bool
    {
        return (bool) $this->get(self::CREDIT);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * @return array<string, mixed> every setting, the defaults for what was never set
     */
    public function all(): array
    {
        // Before the table is there, as during a first migration, the defaults hold.
        return $this->values ??= rescue(
            fn (): array => Cache::rememberForever(self::CACHE, fn (): array => PlatformSetting::query()->pluck('value', 'key')->all()),
            [],
            false,
        ) + self::DEFAULTS;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE);
        $this->values = null;
    }
}
