<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewProvider;
use App\Filament\RunPresenter;
use App\Models\OpenFinding;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Diff\DiffReview;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * One repository's reviews over a period, as its page shows them: the figures and
 * charts of the tenant's overview for this repository alone, and what is going on
 * in it now: its open pull requests, the findings left open after a merge, and the
 * rules its review reads.
 */
class RepositoryOverview extends TenantOverview
{
    /** How many of each list the page shows before it links to the whole of it. */
    public const LIMIT = 8;

    /**
     * @return array<string, mixed>
     */
    public function forRepository(Repository $repository, int $days): array
    {
        $since = now()->subDays($days);
        $runs = $this->repositoryRuns($repository, $since);
        $previous = $repository->reviewRuns()->whereIn('status', self::REVIEWS)
            ->where('started_at', '>=', $since->subDays($days))->where('started_at', '<', $since)->count();
        $verdicts = $this->feedback->forRuns($runs);
        $openFindings = OpenFinding::query()->where('repository_id', $repository->getKey())->whereIn('status', OpenFinding::ACTIVE);

        return [
            'figures' => $this->repositoryFigures($repository, $runs, $previous, $verdicts, $days, (clone $openFindings)->count()),
            'days' => $this->perDay($runs, $days, $repository->tenant),
            'categories' => $this->categories($runs, $verdicts),
            'outcomes' => $this->outcomes->for($runs),
            'reviewers' => $this->reviewers($runs, $verdicts),
            'pullRequests' => $this->pullRequests($repository),
            'openFindings' => $openFindings
                ->orderByRaw("case severity when 'CRITICAL' then 0 when 'HIGH' then 1 when 'MEDIUM' then 2 else 3 end")
                ->latest('id')
                ->limit(self::LIMIT)
                ->get(),
            'rules' => ReviewRules::for($repository),
            'activity' => $repository->reviewRuns()->whereIn('status', self::REVIEWS)->latest('started_at')->limit(self::LIMIT)->get(),
            'running' => $runs->where('status', ReviewRun::STATUS_RUNNING)->count(),
        ];
    }

    /**
     * @return Collection<int, ReviewRun>
     */
    protected function repositoryRuns(Repository $repository, CarbonInterface $from): Collection
    {
        return $repository->reviewRuns()
            ->whereIn('status', self::REVIEWS)
            ->where('started_at', '>=', $from)
            ->get();
    }

    /**
     * @param  Collection<int, ReviewRun>  $runs
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array<int, array{label: string, value: string, note: string, tone: string, bar: int|null}>
     */
    protected function repositoryFigures(Repository $repository, Collection $runs, int $previous, array $verdicts, int $days, int $openFindings): array
    {
        $finished = $runs->where('status', ReviewRun::STATUS_FINISHED);
        $blocked = $finished->filter(fn (ReviewRun $run): bool => strtoupper((string) $run->result) === 'FAILED')->count();
        $durations = $finished->pluck('duration_seconds')->reject(fn (?int $seconds): bool => $seconds === null)->sort()->values();
        $acceptance = $this->acceptance($verdicts);
        $cost = $this->currency->convert((float) $runs->sum(fn (ReviewRun $run): float => (float) $run->cost), $repository->tenant);

        return [
            [
                'label' => 'Reviews', 'value' => (string) $runs->count(), 'tone' => 'neutral', 'bar' => null,
                'note' => $previous === 0
                    ? "None in the {$days} days before"
                    : sprintf('%+d%% vs previous %dd', (int) round(($runs->count() - $previous) / $previous * 100), $days),
            ],
            [
                'label' => 'Changes requested', 'tone' => 'neutral', 'bar' => null,
                'value' => $finished->isEmpty() ? '—' : round($blocked / $finished->count() * 100).'%',
                'note' => $finished->isEmpty() ? 'No finished reviews yet' : "{$blocked} of {$finished->count()} finished",
            ],
            [
                'label' => 'Findings accepted', 'tone' => 'primary', 'bar' => null,
                'value' => $acceptance['percent'] === null ? '—' : "{$acceptance['percent']}%",
                'note' => $acceptance['rated'] === 0 ? 'No findings rated yet' : "{$acceptance['accepted']} of {$acceptance['rated']} rated findings",
            ],
            [
                'label' => 'Median duration', 'tone' => 'neutral', 'bar' => null,
                'value' => $durations->isEmpty() ? '—' : (string) RunPresenter::duration((int) round((float) $durations->median())),
                'note' => $durations->isEmpty() ? 'No finished reviews yet' : 'p90 '.RunPresenter::duration((int) $durations[(int) ceil($durations->count() * 0.9) - 1]),
            ],
            [
                'label' => 'Cost', 'value' => Currency::showOwn($cost, $repository->tenant), 'tone' => 'neutral', 'bar' => null,
                'note' => $runs->isEmpty() ? 'No reviews in the period' : 'about '.Currency::showOwn($cost / $runs->count(), $repository->tenant).' a review',
            ],
            [
                'label' => 'Open findings', 'value' => (string) $openFindings, 'tone' => $openFindings > 0 ? 'danger' : 'neutral', 'bar' => null,
                'note' => 'Left open on merged pull requests',
            ],
        ];
    }

    /**
     * Each reviewer that ran here in the period: how many reviews, what they cost,
     * and how often the team accepted what it found. For which reviewer to keep.
     *
     * @param  Collection<int, ReviewRun>  $runs
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array<int, array{name: string, reviews: int, accepted: string, cost: float, median: string|null}>
     */
    protected function reviewers(Collection $runs, array $verdicts): array
    {
        return $runs->whereNotNull('agent')->groupBy('agent')->map(function (Collection $own, string $agent) use ($verdicts): array {
            $acceptance = $this->acceptance(array_intersect_key($verdicts, $own->keyBy('id')->all()));
            $durations = $own->where('status', ReviewRun::STATUS_FINISHED)->pluck('duration_seconds')->reject(fn (?int $seconds): bool => $seconds === null);

            return [
                'name' => ReviewProvider::tryFrom($agent)?->text() ?? ($agent === DiffReview::AGENT ? 'The diff alone' : $agent),
                'reviews' => $own->count(),
                'accepted' => $acceptance['percent'] === null ? '—' : "{$acceptance['percent']}%",
                'cost' => (float) $own->sum(fn (ReviewRun $run): float => (float) $run->cost),
                'median' => $durations->isEmpty() ? null : RunPresenter::duration((int) round((float) $durations->median())),
            ];
        })->sortByDesc('reviews')->values()->all();
    }

    /**
     * The open pull requests, newest first, each with how its last review ended.
     *
     * @return array<int, array{pullRequest: OpenPullRequest, run: ReviewRun|null}>
     */
    protected function pullRequests(Repository $repository): array
    {
        $pullRequests = OpenPullRequest::query()
            ->where('repository_id', $repository->getKey())
            ->latest('opened_at')
            ->limit(self::LIMIT)
            ->get();

        // Each one's newest review, in one query rather than one per pull request.
        $runs = $repository->reviewRuns()
            ->whereIn('pull_request_id', $pullRequests->pluck('pull_request_id'))
            ->whereIn('status', self::REVIEWS)
            ->where('custom', false)
            ->orderBy('started_at')
            ->get()
            ->keyBy('pull_request_id');

        return $pullRequests->map(fn (OpenPullRequest $pullRequest): array => [
            'pullRequest' => $pullRequest,
            'run' => $runs->get($pullRequest->pull_request_id),
        ])->all();
    }
}
