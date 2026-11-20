<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\RunLog;

/**
 * What a review's steps took, and what its reviewer used while they ran: from the
 * steps its log noted and the samples its sandbox took, kept on the run.
 *
 * A sample is short of processor when it kept nine tenths of what it may use busy,
 * or waited a fifth of the time for a processor it was not given: a sandbox on a
 * part of a processor waits for the rest.
 */
class RunMetrics
{
    /** Share of what it may use from which a sample counts as short of processor. */
    public const BUSY_SHARE = 0.9;

    /** Share of the time waited for a processor from which a sample counts as short of one. */
    public const STOLEN_SHARE = 0.2;

    /** Samples kept on a run, for a chart: of a longer one, every other one, and so on. */
    public const KEPT_SAMPLES = 240;

    public function __construct(protected RunLog $log) {}

    /**
     * Keeps the metrics on the run, when there is anything to say.
     *
     * @param  array{samples: list<array{0: int, 1: float, 2: int, 3: float}>, processors: float|null, memory_mb: int|null}|null  $used  what the reviewer's step was seen to use, or null when it did not run
     */
    public function store(Repository $repository, int $build, ?array $used): void
    {
        $phases = $this->log->phases((int) $repository->getKey(), $build);

        if ($phases === [] && ($used === null || $used['samples'] === [])) {
            return;
        }

        ReviewRun::query()
            ->where('repository_id', $repository->getKey())
            ->where('build_number', $build)
            ->update(['metrics' => json_encode(self::summarize($phases, $used['samples'] ?? [], $used['processors'] ?? null, $used['memory_mb'] ?? null, now()->getTimestamp()))]);
    }

    /**
     * @param  list<array{at: int, name: string}>  $phases  when each step started
     * @param  list<array{0: int, 1: float, 2: int, 3: float}>  $samples
     * @param  int  $end  when the last step ended
     * @return array<string, mixed>
     */
    public static function summarize(array $phases, array $samples, ?float $processors, ?int $memoryMb, int $end): array
    {
        $steps = [];

        foreach ($phases as $index => $phase) {
            $until = $phases[$index + 1]['at'] ?? $end;
            $within = array_values(array_filter($samples, fn (array $sample): bool => $sample[0] >= $phase['at'] && $sample[0] < $until));

            $steps[] = ['name' => $phase['name'], 'seconds' => max(0, $until - $phase['at'])] + self::usage($within, $processors);
        }

        $every = (int) ceil(count($samples) / self::KEPT_SAMPLES);

        return [
            'processors' => $processors,
            'memory_mb' => $memoryMb,
            ...self::usage($samples, $processors),
            'steps' => $steps,
            'samples' => $every <= 1 ? $samples : array_values(array_filter($samples, fn (int $index): bool => $index % $every === 0, ARRAY_FILTER_USE_KEY)),
        ];
    }

    /**
     * @param  list<array{0: int, 1: float, 2: int, 3: float}>  $samples
     * @return array{peak_memory_mb: int|null, cpu_average: float|null, cpu_peak: float|null, starved_percent: int|null}
     */
    protected static function usage(array $samples, ?float $processors): array
    {
        if ($samples === []) {
            return ['peak_memory_mb' => null, 'cpu_average' => null, 'cpu_peak' => null, 'starved_percent' => null];
        }

        $busy = array_column($samples, 1);
        $starved = array_filter($samples, fn (array $sample): bool => $sample[3] >= self::STOLEN_SHARE
            || ($processors !== null && $processors > 0 && $sample[1] >= $processors * self::BUSY_SHARE));

        return [
            'peak_memory_mb' => max(array_column($samples, 2)),
            'cpu_average' => round(array_sum($busy) / count($busy), 2),
            'cpu_peak' => round(max($busy), 2),
            'starved_percent' => (int) round(count($starved) / count($samples) * 100),
        ];
    }
}
