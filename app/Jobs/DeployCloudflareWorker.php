<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Runners\Container\WorkerDeployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Deploys the Cloudflare Worker, asked for on the setup page. On the reviews'
 * queue, whose worker has this installation's copy of it and Node to run Wrangler.
 */
class DeployCloudflareWorker implements ShouldQueue
{
    use Queueable;

    /** Installing Wrangler, then the deploy itself. */
    public int $timeout = 1800;

    public int $tries = 1;

    public static function ask(): void
    {
        Cache::put(WorkerDeployment::DEPLOYING, now()->getTimestamp(), now()->addMinutes(30));

        self::dispatch()
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    public function handle(WorkerDeployment $deployment): void
    {
        $deployment->deploy();
    }
}
