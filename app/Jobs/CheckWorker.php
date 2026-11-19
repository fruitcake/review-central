<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SetupCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at what a review needs on the worker, from the worker: on the queue the
 * reviews go on and as the user they run as. That it is picked up at all is the
 * first thing it shows.
 */
class CheckWorker implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(SetupCheck $setup): void
    {
        $setup->recordWorker();
    }
}
