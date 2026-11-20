<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runners\Container\CloudflareSandbox;
use App\Services\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Has the Cloudflare Worker prepare the sandbox's tools, asked for on the setup
 * page or for a new release of a reviewer's CLI, and keeps how that went for the
 * page to show.
 */
class PrepareCloudflareSandbox implements ShouldQueue
{
    use Queueable;

    public const RESULT = 'cloudflare-sandbox:prepared';

    /** Set when it is asked for, and gone once it is done: for the page to say it is going. */
    public const PREPARING = 'cloudflare-sandbox:preparing';

    /**
     * Puts it on the reviews' queue and notes that it is going: for the CLIs at
     * the versions given, a new release, or as the setup has them now.
     *
     * @param  array<string, string>|null  $versions
     */
    public static function ask(?array $versions = null): void
    {
        $setup = CloudflareSandbox::setupScript($versions);
        Cache::put(self::PREPARING, ['at' => now()->getTimestamp(), 'version' => $setup === null ? null : CloudflareSandbox::setupVersion($setup)], now()->addHour());

        self::dispatch($versions)
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    /**
     * @param  array<string, string>|null  $versions  the CLIs' versions to prepare; once their snapshot is made, reviews install those
     */
    public function __construct(public ?array $versions = null) {}

    /** The Worker answers when it is done: a few minutes for a first install. */
    public int $timeout = 3700;

    public int $tries = 1;

    public function handle(CloudflareSandbox $sandbox, PlatformSettings $settings): void
    {
        $setup = CloudflareSandbox::setupScript($this->versions);

        try {
            $result = $sandbox->prepare($this->versions);
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage()];
        }

        // Only now that their snapshot is there do reviews install these versions:
        // none waits for an install, and one that fails leaves the last in place.
        if ($result['ok'] && $this->versions !== null) {
            $settings->save([PlatformSettings::CLOUDFLARE_CLIS => $this->versions + CloudflareSandbox::pinnedClis()]);
        }

        Cache::forget(self::PREPARING);
        Cache::put(self::RESULT, $result + [
            'at' => now()->getTimestamp(),
            'version' => $setup === null ? null : CloudflareSandbox::setupVersion($setup),
        ], now()->addWeek());
    }
}
