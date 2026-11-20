<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\RunPresenter;
use App\Models\CapacitySample;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\CloudflareRunner;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\ContainerRunner;
use App\Runners\Runners;
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

    /** Reviews with metrics a size is advised on from, at the least. */
    public const SIZING_RUNS = 5;

    /** Room left above the memory nineteen in twenty reviews used at most. */
    public const MEMORY_HEADROOM = 1.25;

    /** Share of the time short of processor, over the steps before and after the model, from which a bigger size is advised. */
    public const STARVED_PERCENT = 25;

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
        // While a review ran on Docker: what this server's load is of the reviews on it.
        $whileDocker = $samples->filter(fn (CapacitySample $sample): bool => $sample->running - (int) $sample->running_sandboxes > 0);
        $dockerLoad = $whileDocker->filter(fn (CapacitySample $sample): bool => $sample->load !== null && $sample->cpus !== null)
            ->map(fn (CapacitySample $sample): float => (float) $sample->load / max(1, (int) $sample->cpus) * 100);
        $dockerMemory = $whileDocker->pluck('memory_used_percent')->filter(fn (?string $percent): bool => $percent !== null)->map(fn (string $percent): float => (float) $percent);
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
            // Docker as the repositories are set now, and what the server did while reviews ran on it.
            'docker' => [
                'in_use' => $this->dockerInUse(),
                'cpu_peak' => $dockerLoad->isEmpty() ? null : (int) round($dockerLoad->max()),
                'memory_peak' => $dockerMemory->isEmpty() ? null : (int) round($dockerMemory->max()),
            ],
            'disk' => $latest?->disk_used_percent === null ? null : (int) round((float) $latest->disk_used_percent),
            'trend' => $this->trend($samples),
        ];

        $sizing = $this->sizing($since);
        $advice = $this->advice($report);

        if ($sizing['advice'] !== null) {
            $advice[] = match (true) {
                $sizing['measuring'] => ['tone' => 'neutral', 'label' => 'Measuring', 'title' => 'Sandbox size', 'body' => $sizing['advice']],
                $sizing['suggested'] === $sizing['instance'] => ['tone' => 'approved', 'label' => 'No action', 'title' => 'Sandbox size', 'body' => $sizing['advice']],
                default => ['tone' => 'changes', 'label' => 'Change size', 'title' => 'Sandbox size', 'body' => $sizing['advice'], 'apply' => $sizing['suggested'], 'settings' => true],
            };
        }

        return $report + ['sizing' => $sizing, 'advice' => $advice, 'sandbox_steps' => $this->sandboxSteps($since)];
    }

    /**
     * What Cloudflare sandboxes used since then, and the size that fits it: the
     * smallest whose memory has a quarter to spare above what nineteen in twenty
     * reviews used at most, one up when the steps around the model were short of
     * processor for a quarter of their time or more. The model's own step waits
     * on the AI provider, and a faster processor does not make that shorter.
     *
     * Processor is told per size, by the reviews that ran on it. A size that was
     * short of processor is not advised again, whatever its memory says; and just
     * after a change of size, until enough reviews ran on the new one, there is
     * nothing to say of its processor yet, so the advice waits rather than send
     * it back to the size it came from.
     *
     * @return array{instance: string, runs: int, peak_memory_mb: int|null, starved_percent: int|null, suggested: string|null, advice: string|null, measuring: bool}
     */
    public function sizing(?CarbonInterface $since = null): array
    {
        $instance = CloudflareSandbox::instance();
        $metrics = ReviewRun::query()
            ->where('sandbox', 'cloudflare')
            ->where('started_at', '>=', $since ?? now()->subDays(30))
            ->whereNotNull('metrics')
            ->get(['metrics', 'sandbox_size'])
            ->filter(fn (ReviewRun $run): bool => ($run->metrics['peak_memory_mb'] ?? null) !== null);
        $none = ['instance' => $instance, 'runs' => $metrics->count(), 'peak_memory_mb' => null, 'starved_percent' => null, 'suggested' => null, 'advice' => null, 'measuring' => false];

        if ($metrics->count() < self::SIZING_RUNS) {
            return $none;
        }

        $memory = $metrics->map(fn (ReviewRun $run): int => (int) $run->metrics['peak_memory_mb'])->sort()->values();
        $peak = (int) $memory[(int) ceil($memory->count() * 0.95) - 1];
        $sizes = array_keys(CloudflareSandbox::INSTANCES);
        $position = fn (?string $size): int => (int) array_search($size, $sizes, true);

        // Short of processor, per size the reviews ran on, where enough of them did.
        $starvedBySize = $metrics->groupBy('sandbox_size')
            ->map(fn (Collection $runs): Collection => $runs->map(fn (ReviewRun $run): ?float => $this->starvedAroundModel((array) $run->metrics))->filter(fn (?float $share): bool => $share !== null))
            ->filter(fn (Collection $shares): bool => $shares->count() >= self::SIZING_RUNS)
            ->map(fn (Collection $shares): int => (int) round($shares->avg()));
        $starvedPercent = $starvedBySize->get($instance);
        $here = $metrics->where('sandbox_size', $instance)->count();

        $memoryGib = fn (string $size): string => rtrim(rtrim(number_format((float) config("review.cloudflare.instances.{$size}.memory_gib"), 2), '0'), '.').' GiB';
        $used = "{$metrics->count()} reviews in a sandbox used at most ".number_format($peak / 1024, 1).' GB of memory (nineteen in twenty)';

        if ($here < self::SIZING_RUNS) {
            return ['instance' => $instance, 'runs' => $metrics->count(), 'peak_memory_mb' => $peak, 'starved_percent' => null, 'suggested' => $instance, 'measuring' => true,
                'advice' => "Sandboxes run on {$instance} since a change of size, and {$here} of the ".self::SIZING_RUNS.' reviews the advice waits for ran on it so far. '
                    .'What the processor needs is told by the reviews on the size itself, so it says more once they have run.'];
        }

        $fits = collect($sizes)->first(fn (string $size): bool => (float) config("review.cloudflare.instances.{$size}.memory_gib") * 1024 >= $peak * self::MEMORY_HEADROOM) ?? end($sizes);
        $suggested = $fits;
        $short = $starvedBySize->filter(fn (int $share): bool => $share >= self::STARVED_PERCENT)->keys();

        // Above every size that was short of processor, this one included.
        foreach ($short as $size) {
            $up = $sizes[min(count($sizes) - 1, $position((string) $size) + 1)];
            $suggested = $position($up) > $position($suggested) ? $up : $suggested;
        }

        $processor = $starvedPercent === null ? '' : ", and around the model were short of processor {$starvedPercent}% of the time";
        $cost = fn (string $size): float => CloudflareSandbox::estimate($size, 3600) ?? 0.0;
        $shortElsewhere = $short->reject(fn (string $size): bool => $size === $instance)->values();

        $advice = match (true) {
            $suggested === $instance => "{$used}{$processor}. {$instance} ({$memoryGib($instance)}) fits.",
            $position($suggested) < $position($instance) => "{$used}{$processor}. {$suggested} ({$memoryGib($suggested)}) fits as well and costs ".(int) round((1 - $cost($suggested) / max(0.0001, $cost($instance))) * 100)."% less per second than {$instance}"
                .($shortElsewhere->isEmpty() ? '.' : '; '.$shortElsewhere->implode(' and ').' was short of processor.'),
            default => "{$used}{$processor}. {$suggested} ({$memoryGib($suggested)}) gives them room; {$instance} is ".($starvedPercent !== null && $starvedPercent >= self::STARVED_PERCENT ? 'short of processor' : 'tight on memory').'.',
        };

        return ['instance' => $instance, 'runs' => $metrics->count(), 'peak_memory_mb' => $peak, 'starved_percent' => $starvedPercent, 'suggested' => $suggested, 'advice' => $advice, 'measuring' => false];
    }

    /**
     * What the reviews in a Cloudflare sandbox took, per size and per step of the
     * review, newest size first: how long each step took on average and for nine
     * in ten, the most memory it used, and how much of its time it waited for a
     * processor. And per size what a whole review took and cost in the sandbox.
     *
     * @return array<int, array{size: string, runs: int, seconds: int|null, sandbox_seconds: int|null, cost: float|null, steps: array<int, array{name: string, runs: int, average: int, p90: int, peak_memory_mb: int|null, starved_percent: int|null}>}>
     */
    public function sandboxSteps(CarbonInterface $since): array
    {
        $runs = ReviewRun::query()
            ->where('sandbox', 'cloudflare')
            ->where('started_at', '>=', $since)
            ->whereNotNull('metrics')
            ->latest('started_at')
            ->get(['metrics', 'sandbox_size', 'duration_seconds', 'sandbox_seconds', 'sandbox_cost', 'started_at']);

        $average = fn (Collection $values): ?int => $values->isEmpty() ? null : (int) round($values->avg());

        return $runs->groupBy(fn (ReviewRun $run): string => (string) ($run->sandbox_size ?? 'unknown'))->map(function (Collection $ofSize, string $size) use ($average): array {
            $steps = $ofSize->flatMap(fn (ReviewRun $run): array => array_values(array_filter((array) ($run->metrics['steps'] ?? []), fn (mixed $step): bool => is_array($step) && is_string($step['name'] ?? null))))
                ->groupBy('name')
                ->map(function (Collection $ofStep, string $name) use ($average): array {
                    $seconds = $ofStep->pluck('seconds')->filter(fn (mixed $value): bool => is_numeric($value))->map(fn (mixed $value): int => (int) $value)->sort()->values();

                    return [
                        'name' => $name,
                        'runs' => $ofStep->count(),
                        'average' => (int) round($seconds->avg() ?? 0),
                        'p90' => $seconds->isEmpty() ? 0 : (int) $seconds[(int) ceil($seconds->count() * 0.9) - 1],
                        'peak_memory_mb' => ($memory = $ofStep->pluck('peak_memory_mb')->filter(fn (mixed $value): bool => is_numeric($value))->map(fn (mixed $value): int => (int) $value))->isEmpty() ? null : (int) $memory->max(),
                        'starved_percent' => $average($ofStep->pluck('starved_percent')->filter(fn (mixed $value): bool => is_numeric($value))),
                    ];
                })
                ->values()
                ->all();

            return [
                'size' => $size,
                'runs' => $ofSize->count(),
                'seconds' => $average($ofSize->pluck('duration_seconds')->filter(fn (?int $value): bool => $value !== null)),
                'sandbox_seconds' => $average($ofSize->pluck('sandbox_seconds')->filter(fn (?int $value): bool => $value !== null)),
                'cost' => ($costs = $ofSize->pluck('sandbox_cost')->filter(fn (mixed $value): bool => $value !== null))->isEmpty() ? null : round((float) $costs->avg(), 4),
                'steps' => $steps,
            ];
        })->values()->all();
    }

    /**
     * The share of the time short of processor in the steps other than the
     * model's, weighed by how long each took.
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function starvedAroundModel(array $metrics): ?float
    {
        // The model's: the reviewer, and Codex's own review before it, which waits on the model as much.
        $steps = array_filter((array) ($metrics['steps'] ?? []), fn (mixed $step): bool => is_array($step) && ! in_array($step['name'] ?? null, ['reviewer', 'reviewer (candidates)'], true) && ($step['starved_percent'] ?? null) !== null && ($step['seconds'] ?? 0) > 0);
        $seconds = array_sum(array_column($steps, 'seconds'));

        return $seconds === 0 ? null : array_sum(array_map(fn (array $step): float => $step['starved_percent'] * $step['seconds'], $steps)) / $seconds;
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
     * Whether any repository reviews on Docker as it is set now: by its own
     * choice of runner, or by the platform's.
     */
    protected function dockerInUse(): bool
    {
        $runners = app(Runners::class);

        return Repository::query()->where('reviews_enabled', true)->distinct()->pluck('review_runner')
            ->contains(fn (?string $runner): bool => $runners->nameFor($runner) === ContainerRunner::NAME);
    }

    /**
     * What the figures say to do, most pressing first.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array{tone: string, title: string, body: string, settings?: bool}>
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
        // Only while a repository still reviews on Docker, and from the load while
        // a review ran there: one moved to Cloudflare since says nothing of now.
        $docker = ($report['docker']['in_use'] ?? false) === true;
        $dockerCpu = $report['docker']['cpu_peak'] ?? null;
        $dockerMemory = $report['docker']['memory_peak'] ?? null;

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

        if (! $waiting && $docker && $dockerCpu !== null && $dockerCpu >= self::HIGH_LOAD_PERCENT) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'The processors are the limit',
                'body' => "Load peaks at {$dockerCpu}% of the {$report['cpu']['cpus']} processors while reviews run on Docker. They take longer at those moments; more cores, fewer reviews side by side{$elsewhere}.",
                'settings' => $elsewhere !== '',
            ];
        }

        if (! $waiting && $docker && $dockerMemory !== null && $dockerMemory >= self::HIGH_MEMORY_PERCENT) {
            $advice[] = [
                'tone' => 'danger',
                'title' => 'Memory is nearly full',
                'body' => "It peaks at {$dockerMemory}% while reviews run on Docker. Each review there may use up to ".config('review.container.memory')."; more memory, fewer reviews side by side{$elsewhere}.",
                'settings' => $elsewhere !== '',
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
