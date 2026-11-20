<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Themes;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

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

    /** The Cloudflare account the sandbox's Worker and bucket are in. */
    public const CLOUDFLARE_ACCOUNT = 'cloudflare_account';

    /** A Cloudflare API token that deploys them, kept encrypted. */
    public const CLOUDFLARE_API_TOKEN = 'cloudflare_api_token';

    /** What the Worker is called on Cloudflare. */
    public const CLOUDFLARE_WORKER = 'cloudflare_worker';

    /** The bucket the Worker and this installation hand files through. */
    public const CLOUDFLARE_BUCKET = 'cloudflare_bucket';

    /** The bucket's own keys, for its S3 API, the secret kept encrypted. */
    public const R2_ACCESS_KEY_ID = 'r2_access_key_id';

    public const R2_SECRET_ACCESS_KEY = 'r2_secret_access_key';

    /** Where the deployed Worker answers, as its deploy said. */
    public const CLOUDFLARE_URL = 'cloudflare_url';

    /** The token this installation asks the Worker with, made for its deploy, kept encrypted. */
    public const CLOUDFLARE_TOKEN = 'cloudflare_token';

    /** Kept encrypted, and never shown again. */
    public const SECRETS = [self::CLOUDFLARE_API_TOKEN, self::R2_SECRET_ACCESS_KEY, self::CLOUDFLARE_TOKEN];

    public const DEFAULT_WORKER = 'review-central-sandbox';

    public const DEFAULT_BUCKET = 'review-central';

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
        self::CLOUDFLARE_ACCOUNT => null,
        self::CLOUDFLARE_API_TOKEN => null,
        self::CLOUDFLARE_WORKER => null,
        self::CLOUDFLARE_BUCKET => null,
        self::R2_ACCESS_KEY_ID => null,
        self::R2_SECRET_ACCESS_KEY => null,
        self::CLOUDFLARE_URL => null,
        self::CLOUDFLARE_TOKEN => null,
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

    /**
     * Fills the configuration of the Cloudflare sandbox and its bucket from these
     * settings, where the environment leaves it empty: what .env sets holds.
     */
    public function applyCloudflare(): void
    {
        $account = $this->text(self::CLOUDFLARE_ACCOUNT);
        $disk = (string) config('review.cloudflare.disk');
        $values = [
            'review.cloudflare.account' => $account,
            'review.cloudflare.api_token' => $this->secret(self::CLOUDFLARE_API_TOKEN),
            'review.cloudflare.worker' => $this->text(self::CLOUDFLARE_WORKER),
            'review.cloudflare.url' => $this->text(self::CLOUDFLARE_URL),
            'review.cloudflare.token' => $this->secret(self::CLOUDFLARE_TOKEN),
            "filesystems.disks.{$disk}.key" => $this->text(self::R2_ACCESS_KEY_ID),
            "filesystems.disks.{$disk}.secret" => $this->secret(self::R2_SECRET_ACCESS_KEY),
            "filesystems.disks.{$disk}.bucket" => $this->text(self::CLOUDFLARE_BUCKET) ?? ($account === null ? null : self::DEFAULT_BUCKET),
            "filesystems.disks.{$disk}.endpoint" => $account === null ? null : "https://{$account}.r2.cloudflarestorage.com",
        ];
        $changed = false;

        foreach ($values as $key => $value) {
            // Empty in the environment, or what these settings put there before.
            $ours = blank(config($key)) || (array_key_exists($key, self::$applied) && self::$applied[$key] === config($key));

            if ($value !== null && $ours && config($key) !== $value) {
                config([$key => $value]);
                self::$applied[$key] = $value;
                $changed = true;
            }
        }

        // A disk made before holds the keys it was made with.
        if ($changed && $disk !== '') {
            rescue(fn () => app('filesystem')->forgetDisk($disk), null, false);
        }
    }

    /**
     * What applyCloudflare() put in the configuration, so a later change to the
     * settings replaces it, and never what the environment set.
     *
     * @var array<string, string>
     */
    protected static array $applied = [];

    /**
     * A secret as it was saved, or null when none is, or when it was encrypted
     * with another APP_KEY.
     */
    public function secret(string $key): ?string
    {
        $value = $this->get($key);

        try {
            return is_string($value) && $value !== '' ? Crypt::decryptString($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A setting that is text, or null when it is empty.
     */
    public function text(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
            // A secret left empty is kept as it was; one that is given is kept encrypted.
            if (in_array($key, self::SECRETS, true)) {
                if (blank($value)) {
                    continue;
                }

                $value = Crypt::encryptString((string) $value);
            }

            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE);
        $this->values = null;
    }
}
