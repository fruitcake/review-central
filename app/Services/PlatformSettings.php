<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\Integrations;
use App\Filament\Themes;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;
use RuntimeException;
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

    /** The size a sandbox runs in, as Cloudflare names it. */
    public const CLOUDFLARE_INSTANCE = 'cloudflare_instance';

    /** The bucket the Worker and this installation hand files through. */
    public const CLOUDFLARE_BUCKET = 'cloudflare_bucket';

    /** The bucket's own keys, for its S3 API, the secret kept encrypted. */
    public const R2_ACCESS_KEY_ID = 'r2_access_key_id';

    public const R2_SECRET_ACCESS_KEY = 'r2_secret_access_key';

    /** Where the deployed Worker answers, as its deploy said. */
    public const CLOUDFLARE_URL = 'cloudflare_url';

    /** The token this installation asks the Worker with, made for its deploy, kept encrypted. */
    public const CLOUDFLARE_TOKEN = 'cloudflare_token';

    /**
     * The versions of the reviewers' CLIs the sandbox's setup installs, by npm
     * package: those of the newest snapshot, set once it is made.
     */
    public const CLOUDFLARE_CLIS = 'cloudflare_clis';

    /**
     * The key pair the OAuth server signs its tokens with, as encrypted JSON: made
     * the first time a token is issued or read, so every server of the
     * installation signs with the same one. PASSPORT_PRIVATE_KEY and
     * PASSPORT_PUBLIC_KEY, or the key files Passport makes, go first.
     */
    public const OAUTH_KEYS = 'oauth_keys';

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
        self::CLOUDFLARE_INSTANCE => null,
        self::R2_ACCESS_KEY_ID => null,
        self::R2_SECRET_ACCESS_KEY => null,
        self::CLOUDFLARE_URL => null,
        self::CLOUDFLARE_TOKEN => null,
        self::CLOUDFLARE_CLIS => null,
        self::OAUTH_KEYS => null,
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
        // Not into a configuration cache: that would keep these values as if the
        // environment had set them, so a later change here would not take, and
        // write the secrets out in plain text.
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        $account = $this->text(self::CLOUDFLARE_ACCOUNT);
        $disk = (string) config('review.cloudflare.disk');
        $values = [
            'review.cloudflare.account' => $account,
            'review.cloudflare.api_token' => $this->secret(self::CLOUDFLARE_API_TOKEN),
            'review.cloudflare.worker' => $this->text(self::CLOUDFLARE_WORKER),
            'review.cloudflare.instance' => $this->text(self::CLOUDFLARE_INSTANCE),
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
     * Both: the Cloudflare sandbox and the apps. Again for every job, as a worker
     * outlives a change made here.
     */
    public function apply(): void
    {
        $this->applyCloudflare();
        Integrations::apply();
    }

    /**
     * The environment variable that sets a Cloudflare setting instead, when it
     * does: then what is saved here is not used. Told by the configuration
     * holding a value these settings did not put there, as env() says nothing
     * once the configuration is cached.
     */
    public function setByEnvironment(string $setting): ?string
    {
        $disk = (string) config('review.cloudflare.disk');
        [$variable, $key] = match ($setting) {
            self::CLOUDFLARE_ACCOUNT => ['CLOUDFLARE_ACCOUNT_ID', 'review.cloudflare.account'],
            self::CLOUDFLARE_API_TOKEN => ['CLOUDFLARE_API_TOKEN', 'review.cloudflare.api_token'],
            self::CLOUDFLARE_WORKER => ['REVIEW_CLOUDFLARE_WORKER', 'review.cloudflare.worker'],
            self::CLOUDFLARE_INSTANCE => ['REVIEW_CLOUDFLARE_INSTANCE', 'review.cloudflare.instance'],
            self::CLOUDFLARE_BUCKET => ['R2_BUCKET', "filesystems.disks.{$disk}.bucket"],
            self::R2_ACCESS_KEY_ID => ['R2_ACCESS_KEY_ID', "filesystems.disks.{$disk}.key"],
            self::R2_SECRET_ACCESS_KEY => ['R2_SECRET_ACCESS_KEY', "filesystems.disks.{$disk}.secret"],
            default => [null, null],
        };

        if ($key === null || blank($value = config($key))) {
            return null;
        }

        return array_key_exists($key, self::$applied) && self::$applied[$key] === $value ? null : $variable;
    }

    /**
     * Puts the OAuth server's key pair in the configuration, made and kept here
     * the first time, unless the environment or Passport's key files give one.
     */
    public function applyOAuthKeys(): void
    {
        if (filled(config('passport.private_key')) || is_file(Passport::keyPath('oauth-private.key'))) {
            return;
        }

        $keys = $this->oauthKeys();

        config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
    }

    /**
     * The key pair as it is kept, made when there is none, or when the one kept
     * was encrypted with another APP_KEY and so cannot be read. Read from the
     * table rather than the cache, which another server may have made before the
     * pair was kept.
     *
     * @return array{private: string, public: string}
     */
    protected function oauthKeys(): array
    {
        $row = PlatformSetting::query()->find(self::OAUTH_KEYS);

        if (($keys = self::decodeOAuthKeys($row?->value)) !== null) {
            return $keys;
        }

        $private = RSA::createKey(2048);
        $value = json_encode(Crypt::encryptString((string) json_encode(['private' => (string) $private, 'public' => (string) $private->getPublicKey()])));

        // Two servers making one at once keep the first that was saved. An unreadable
        // pair is replaced under a lock, and only while it still cannot be read: once
        // another server has replaced it, tokens may already be signed with that one.
        $row === null
            ? PlatformSetting::query()->insertOrIgnore(['key' => self::OAUTH_KEYS, 'value' => $value, 'created_at' => now(), 'updated_at' => now()])
            : DB::transaction(function () use ($value): void {
                $locked = PlatformSetting::query()->lockForUpdate()->find(self::OAUTH_KEYS);

                if ($locked !== null && self::decodeOAuthKeys($locked->value) === null) {
                    PlatformSetting::query()->whereKey(self::OAUTH_KEYS)->update(['value' => $value, 'updated_at' => now()]);
                }
            });

        Cache::forget(self::CACHE);
        $this->values = null;

        return self::decodeOAuthKeys(PlatformSetting::query()->find(self::OAUTH_KEYS)?->value)
            ?? throw new RuntimeException('The OAuth keys could not be kept.');
    }

    /**
     * The key pair from its kept value, or null when it cannot be read.
     *
     * @return array{private: string, public: string}|null
     */
    protected static function decodeOAuthKeys(mixed $value): ?array
    {
        try {
            $keys = is_string($value) && $value !== '' ? json_decode(Crypt::decryptString($value), true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($keys) && is_string($keys['private'] ?? null) && is_string($keys['public'] ?? null) ? ['private' => $keys['private'], 'public' => $keys['public']] : null;
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

                // Pasted, often with a space or a line break that would make it another key.
                $value = Crypt::encryptString(trim((string) $value));
            }

            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE);
        $this->values = null;
    }
}
