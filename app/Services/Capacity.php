<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\RunPresenter;
use App\Models\CapacitySample;
use App\Models\ReviewRun;
use App\Runners\CloudflareRunner;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\ContainerRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Whether the installation has room: how long reviews wait and take, how often
 * every worker is busy, and how loaded the server is. From the samples taken each
 * minute and from the runs themselves.
 */
class Capacity
{
    /** Share of the time with every worker busy from which waiting becomes normal. */
    public const SATURATED_PERCENT = 20;

    /** A review that waits this long for a worker, at the ninth decile, is waiting too long. */
    public const SLOW_WAIT_SECONDS = 120;

    public const HIGH_LOAD_PERCENT = 80;

    public const HIGH_MEMORY_PERCENT = 85;

    public const HIGH_DISK_PERCENT = 80;

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonInterface $since): array
    {
        $samples = CapacitySample::query()->where('sampled_at', '>=', $since)->orderBy('sampled_at')->get();
        $runs = ReviewRun::query()
            ->where('started_at', '>=', $since)
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->get(['duration_seconds', 'queue_seconds', 'runner']);
        // Of the reviews on the workers, the share whose reviewer ran in a Cloudflare
        // sandbox: those take a worker, and next to nothing of this server.
        $onWorkers = $runs->whereIn('runner', [ContainerRunner::NAME, CloudflareRunner::NAME]);
        $sandboxShare = $onWorkers->isEmpty() ? 0 : (int) round($onWorkers->where('runner', CloudflareRunner::NAME)->count() / $onWorkers->count() * 100);

        $waits = $runs->pluck('queue_seconds')->filter(fn (?int $seconds): bool => $seconds !== null)->sort()->values();
        $durations = $runs->pluck('duration_seconds')->filter(fn (?int $seconds): bool => $seconds !== null)->sort()->values();
        $load = $samples->filter(fn (CapacitySample $sample): bool => $sample->load !== null && $sample->cpus !== null)
            ->map(fn (CapacitySample $sample): float => (float) $sample->load / max(1, (int) $sample->cpus) * 100)->values();
        $memory = $samples->pluck('memory_used_percent')->filter(fn (?string $percent): bool => $percent !== null)->map(fn (string $percent): float => (float) $percent)->values();
        $latest = $samples->last();

        $report = [
            'samples' => $samples->count(),
            'now' => $latest === null ? null : [
                'running' => $latest->running,
                'running_sandboxes' => (int) $latest->running_sandboxes,
                'waiting' => $latest->waiting,
                'held' => $latest->held,
                'workers' => $latest->workers,
                'at' => $latest->sampled_at,
            ],
            'reviews' => $runs->count(),
            'sandbox_share' => $sandboxShare,
            'by_runner' => $runs->countBy('runner')->all(),
            'wait' => $this->spread($waits),
            'duration' => $this->spread($durations),
            'saturated' => $this->share($samples, fn (CapacitySample $sample): bool => $sample->workers > 0 && $sample->running >= $sample->workers),
            'queued' => $this->share($samples, fn (CapacitySample $sample): bool => $sample->waiting > 0),
            'peak_running' => $samples->max('running'),
            'peak_waiting' => $samples->max('waiting'),
            'peak_held' => $samples->max('held'),
            'cpu' => ['average' => $load->isEmpty() ? null : (int) round($load->avg()), 'peak' => $load->isEmpty() ? null : (int) round($load->max()), 'cpus' => $latest?->cpus],
            'memory' => ['average' => $memory->isEmpty() ? null : (int) round($memory->avg()), 'peak' => $memory->isEmpty() ? null : (int) round($memory->max())],
            'disk' => $latest?->disk_used_percent === null ? null : (int) round((float) $latest->disk_used_percent),
            'trend' => $this->trend($samples),
        ];

        return $report + ['advice' => $this->advice($report)];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function waitingLine(array $report): string
    {
        $wait = $report['wait'] === null ? '' : 'One review in ten waits '.RunPresenter::duration($report['wait']['p90']).' or longer for a worker. ';

        return $wait."Every worker was busy {$report['saturated']}% of the time.";
    }

    /**
     * @param  Collection<int, int>  $sorted
     * @return array{average: int, p90: int, max: int}|null
     */
    protected function spread(Collection $sorted): ?array
    {
        return $sorted->isEmpty() ? null : [
            'average' => (int) round($sorted->avg()),
            // Nearest rank: the value nine in ten stay at or below.
            'p90' => (int) $sorted[(int) ceil($sorted->count() * 0.9) - 1],
            'max' => (int) $sorted->last(),
        ];
    }

    /**
     * @param  Collection<int, CapacitySample>  $samples
     */
    protected function share(Collection $samples, callable $when): ?int
    {
        return $samples->isEmpty() ? null : (int) round($samples->filter($when)->count() / $samples->count() * 100);
    }

    /**
     * The period in at most sixty slices, each with its busiest moment, to draw.
     *
     * @param  Collection<int, CapacitySample>  $samples
     * @return array<int, array{at: string, running: int, waiting: int, held: int, workers: int, load: int|null}>
     */
    protected function trend(Collection $samples): array
    {
        if ($samples->isEmpty()) {
            return [];
        }

        return $samples->chunk((int) max(1, ceil($samples->count() / 60)))->map(function (Collection $slice): array {
            $loads = $slice->filter(fn (CapacitySample $sample): bool => $sample->load !== null && $sample->cpus !== null)
                ->map(fn (CapacitySample $sample): float => (float) $sample->load / max(1, (int) $sample->cpus) * 100);

            return [
                'at' => $slice->first()->sampled_at->format('j M H:i'),
                'running' => (int) $slice->max('running'),
                'sandboxes' => (int) $slice->max('running_sandboxes'),
                'waiting' => (int) $slice->max('waiting'),
                'held' => (int) $slice->max('held'),
                'workers' => (int) $slice->max('workers'),
                'load' => $loads->isEmpty() ? null : (int) round($loads->max()),
            ];
        })->values()->all();
    }

    /**
     * What the figures say to do, most pressing first.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array{tone: string, title: string, body: string}>
     */
    protected function advice(array $report): array
    {
        if ($report['samples'] === 0) {
            return [[
                'tone' => 'neutral',
                'title' => 'No measurements yet',
                'body' => 'The scheduler takes one each minute. Check that `php artisan schedule:run` runs every minute on this server.',
            ]];
        }

        $advice = [];
        $serverHasRoom = ($report['cpu']['peak'] ?? 0) < self::HIGH_LOAD_PERCENT && ($report['memory']['peak'] ?? 0) < self::HIGH_MEMORY_PERCENT;
        $waiting = ($report['saturated'] ?? 0) >= self::SATURATED_PERCENT || ($report['wait']['p90'] ?? 0) > self::SLOW_WAIT_SECONDS;
        // Reviews in a Cloudflare sandbox take a worker each and little of this
        // server: what this server's processor and memory say is of the rest.
        $sandboxes = ($report['sandbox_share'] ?? 0) >= 50;
        $docker = ($report['sandbox_share'] ?? 0) < 100;

        if ($waiting && $sandboxes) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'Reviews wait for a worker',
                'body' => $this->waitingLine($report)." {$report['sandbox_share']}% of them ran in a Cloudflare sandbox, where a worker only waits for the sandbox and uses little of this server. Raise REVIEW_CONTAINER_PROCESSES; the sandboxes are the review's own machines.",
            ];
        } elseif ($waiting && $serverHasRoom) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'Reviews wait for a worker while the server has room',
                'body' => $this->waitingLine($report).' Processor and memory stay below their limits, so raise REVIEW_CONTAINER_PROCESSES rather than the server.',
            ];
        } elseif ($waiting) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'Reviews wait, and the server is full',
                'body' => $this->waitingLine($report)." The server peaks at {$report['cpu']['peak']}% processor and {$report['memory']['peak']}% memory, so a bigger server comes first and more workers after.",
            ];
        }

        $elsewhere = CloudflareSandbox::configured() ? ', or run them in Cloudflare sandboxes' : '';

        if (! $waiting && $docker && ($report['cpu']['peak'] ?? 0) >= self::HIGH_LOAD_PERCENT) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'The processors are the limit',
                'body' => "Load peaks at {$report['cpu']['peak']}% of the {$report['cpu']['cpus']} processors, from the reviews on Docker. They take longer at those moments; more cores, fewer reviews side by side{$elsewhere}.",
            ];
        }

        if (! $waiting && $docker && ($report['memory']['peak'] ?? 0) >= self::HIGH_MEMORY_PERCENT) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'Memory is nearly full',
                'body' => "It peaks at {$report['memory']['peak']}%. Each review on Docker may use up to ".config('review.container.memory')."; more memory, fewer reviews side by side{$elsewhere}.",
            ];
        }

        if (($report['disk'] ?? 0) >= self::HIGH_DISK_PERCENT) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'The disk is filling up',
                'body' => "{$report['disk']}% is in use. Every review clones its repository; old Docker images and logs are the first to clear.",
            ];
        }

        return $advice ?: [[
            'tone' => 'approved',
            'title' => 'The installation has room',
            'body' => 'Reviews start without waiting, and processor, memory and disk stay below their limits. Nothing to upgrade.',
        ]];
    }
}
