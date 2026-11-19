<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CapacitySample;
use App\Models\ReviewRun;
use App\Runners\CloudflareRunner;
use App\Runners\ContainerRunner;
use App\Services\SystemLoad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class SampleCapacity extends Command
{
    /** Days of samples to keep: a month shows a trend, and a minute a row adds up. */
    public const KEEP_DAYS = 30;

    /** Hours after which a review that was turned away no longer counts as held. */
    public const HELD_HOURS = 2;

    protected $signature = 'review:sample-capacity';

    protected $description = 'Record how busy the installation is, for the capacity figures in the admin panel';

    public function handle(SystemLoad $system): int
    {
        CapacitySample::query()->create([
            'sampled_at' => now(),
            'running' => ReviewRun::running()->whereIn('runner', [ContainerRunner::NAME, CloudflareRunner::NAME])->count(),
            'running_sandboxes' => ReviewRun::running()->where('runner', CloudflareRunner::NAME)->count(),
            'waiting' => $this->waiting(),
            'held' => $this->held(),
            'workers' => (int) config('review.container.processes'),
            'load' => $system->load(),
            'cpus' => $system->processors(),
            'memory_used_percent' => $system->memoryUsedPercent(),
            'disk_used_percent' => $system->diskUsedPercent(storage_path()),
        ]);

        CapacitySample::query()->where('sampled_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return self::SUCCESS;
    }

    /**
     * Reviews queued and not picked up yet: these wait for a free worker. Not the
     * queue's whole size, which also counts the reviews a worker is running.
     *
     * A queue that cannot be asked counts as empty: a sample without it still says
     * what the server was doing.
     */
    protected function waiting(): int
    {
        return (int) rescue(
            fn (): int => Queue::connection(config('review.container.connection') ?: null)->pendingSize((string) config('review.container.queue')),
            0,
            false,
        );
    }

    /**
     * Reviews turned away because another review of the same pull request is running,
     * to start when that one is done. More workers would not start them sooner.
     */
    protected function held(): int
    {
        return ReviewRun::query()->awaitingRestart()
            ->whereIn('runner', [ContainerRunner::NAME, CloudflareRunner::NAME])
            // One that has waited this long is not waiting any more: its pull request was left.
            ->where('started_at', '>=', now()->subHours(self::HELD_HOURS))
            ->count();
    }
}
