<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runners\Container\CloudflareSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Has the Cloudflare Worker prepare the sandbox's tools, asked for on the setup
 * page, and keeps how that went for the page to show.
 */
class PrepareCloudflareSandbox implements ShouldQueue
{
    use Queueable;

    public const RESULT = 'cloudflare-sandbox:prepared';

    /** Set when it is asked for, and gone once it is done: for the page to say it is going. */
    public const PREPARING = 'cloudflare-sandbox:preparing';

    /**
     * Puts it on the reviews' queue and notes that it is going.
     */
    public static function ask(): void
    {
        $setup = CloudflareSandbox::setupScript();
        Cache::put(self::PREPARING, ['at' => now()->getTimestamp(), 'version' => $setup === null ? null : CloudflareSandbox::setupVersion($setup)], now()->addHour());

        self::dispatch()
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    /** The Worker answers when it is done: a few minutes for a first install. */
    public int $timeout = 3700;

    public int $tries = 1;

    public function handle(CloudflareSandbox $sandbox): void
    {
        $setup = CloudflareSandbox::setupScript();

        try {
            $result = $sandbox->prepare();
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage()];
        }

        Cache::forget(self::PREPARING);
        Cache::put(self::RESULT, $result + [
            'at' => now()->getTimestamp(),
            'version' => $setup === null ? null : CloudflareSandbox::setupVersion($setup),
        ], now()->addWeek());
    }
}
