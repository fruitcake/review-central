<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictReason;
use App\Models\FindingVerdict;
use App\Models\ReviewRun;
use Illuminate\Support\Collection;

/**
 * How the findings of a period ended, whichever way people answered them: on the
 * pull request, on the review's page or from an AI assistant. Each review sets the
 * status of the findings it reports from what it then sees, so the last status a
 * review reported for a finding is how it ended.
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
        'outdated' => ['outdated'],
        // The reviewer could no longer establish it, on code that did not change.
        'unreported' => ['unreported'],
    ];

    /**
     * What each outcome is called, in the user's language.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'fixed' => __('Fixed'),
            'acknowledged' => __('Acknowledged'),
            'false_positive' => __('False positive'),
            'open' => __('Still open'),
            'outdated' => __('Code changed'),
            'unreported' => __('Not reported again'),
        ];
    }

    /**
     * @param  Collection<int, ReviewRun>  $runs  of the period, with their findings
     * @return array{total: int, outcomes: array<int, array{key: string, label: string, count: int}>, reasons: array<int, array{key: string, label: string, count: int}>}
     */
    public function for(Collection $runs): array
    {
        $counts = array_fill_keys(array_keys(self::OUTCOMES), 0);

        foreach ($this->lastStatuses($runs) as $status) {
            foreach (self::OUTCOMES as $outcome => $statuses) {
                if (in_array($status, $statuses, true)) {
                    $counts[$outcome]++;
                }
            }
        }

        $reasons = $runs->isEmpty() ? collect() : FindingVerdict::query()
            ->whereIn('review_run_id', $runs->pluck('id'))
            ->whereNotNull('reason')
            ->get(['reason'])
            ->countBy(fn (FindingVerdict $verdict): string => $verdict->reason->value ?? '');

        $labels = self::labels();

        return [
            'total' => array_sum($counts),
            'outcomes' => array_map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key], 'count' => $counts[$key]], array_keys($counts)),
            'reasons' => collect(FindingVerdictReason::cases())
                ->map(fn (FindingVerdictReason $reason): array => ['key' => $reason->value, 'label' => $reason->text(), 'count' => (int) $reasons->get($reason->value, 0)])
                ->filter(fn (array $reason): bool => $reason['count'] > 0)
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * The last status reviews reported for each finding of each pull request: a
     * review that leaves out a finding settled before leaves its status as it was.
     * Only reviews that reported count, not one that failed to publish, and no trial.
     *
     * @param  Collection<int, ReviewRun>  $runs
     * @return array<string, string> by "repository:pull request:finding"
     */
    protected function lastStatuses(Collection $runs): array
    {
        $statuses = [];

        $reported = $runs
            ->filter(fn (ReviewRun $run): bool => $run->pull_request_id !== null && ! $run->custom && in_array($run->status, PullRequestFeedback::REPORTED, true))
            ->sortBy('started_at');

        foreach ($reported as $run) {
            foreach ($run->findings ?? [] as $finding) {
                if (filled($finding['id'] ?? null)) {
                    $statuses["{$run->repository_id}:{$run->pull_request_id}:{$finding['id']}"] = strtolower((string) ($finding['status'] ?? 'new'));
                }
            }
        }

        return $statuses;
    }
}
