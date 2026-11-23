<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictReason;
use App\Models\FindingVerdict;
use App\Models\ReviewRun;
use Illuminate\Support\Collection;

/**
 * How the findings of a period ended, whichever way people answered them: on the
 * pull request, on the review's page or from an AI assistant. The next review sets
 * each finding's status from what it then sees, so the newest review of each pull
 * request has the end of every finding raised on it.
 */
class FindingOutcomes
{
    /**
     * The outcomes shown, in order, with the statuses each counts.
     *
     * @var array<string, array<int, string>>
     */
    public const OUTCOMES = [
        'fixed' => ['fixed'],
        'acknowledged' => ['acknowledged'],
        'false_positive' => ['false_positive'],
        'open' => ReviewPublisher::OPEN,
        'gone' => ['outdated', 'unreported'],
    ];

    public const LABELS = [
        'fixed' => 'Fixed',
        'acknowledged' => 'Acknowledged',
        'false_positive' => 'False positive',
        'open' => 'Still open',
        'gone' => 'Code changed',
    ];

    /**
     * @param  Collection<int, ReviewRun>  $runs  of the period, with their findings
     * @return array{total: int, outcomes: array<int, array{key: string, label: string, count: int}>, reasons: array<int, array{key: string, label: string, count: int}>}
     */
    public function for(Collection $runs): array
    {
        $counts = array_fill_keys(array_keys(self::OUTCOMES), 0);

        foreach ($this->newestPerPullRequest($runs) as $run) {
            foreach ($run->findings ?? [] as $finding) {
                $status = strtolower((string) ($finding['status'] ?? 'new'));

                foreach (self::OUTCOMES as $outcome => $statuses) {
                    if (in_array($status, $statuses, true)) {
                        $counts[$outcome]++;
                    }
                }
            }
        }

        $reasons = $runs->isEmpty() ? collect() : FindingVerdict::query()
            ->whereIn('review_run_id', $runs->pluck('id'))
            ->whereNotNull('reason')
            ->get(['reason'])
            ->countBy(fn (FindingVerdict $verdict): string => $verdict->reason->value ?? '');

        return [
            'total' => array_sum($counts),
            'outcomes' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::LABELS[$key], 'count' => $counts[$key]], array_keys($counts)),
            'reasons' => collect(FindingVerdictReason::cases())
                ->map(fn (FindingVerdictReason $reason): array => ['key' => $reason->value, 'label' => $reason->text(), 'count' => (int) $reasons->get($reason->value, 0)])
                ->filter(fn (array $reason): bool => $reason['count'] > 0)
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * The newest review of each pull request among the runs, trials left out.
     *
     * @param  Collection<int, ReviewRun>  $runs
     * @return Collection<int, ReviewRun>
     */
    protected function newestPerPullRequest(Collection $runs): Collection
    {
        return $runs
            ->filter(fn (ReviewRun $run): bool => $run->pull_request_id !== null && ! $run->custom && $run->findings !== null)
            ->sortByDesc('started_at')
            ->unique(fn (ReviewRun $run): string => "{$run->repository_id}:{$run->pull_request_id}")
            ->values();
    }
}
