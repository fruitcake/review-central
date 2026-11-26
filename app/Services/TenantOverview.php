<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionKind;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\LocalTime;
use App\Filament\RunPresenter;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * A tenant's reviews over a period, as its overview page shows them: what needs
 * someone's attention, the figures, and where they come from.
 */
class TenantOverview
{
    public function __construct(protected FindingFeedback $feedback, protected Currency $currency, protected FindingOutcomes $outcomes) {}

    /**
     * The runs that were a review, or tried to be. A run that was turned away,
     * restarted or skipped reviewed nothing and would only inflate the count.
     */
    public const REVIEWS = [ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_FINISHED, ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT];

    public const CATEGORIES = ['correctness', 'security', 'maintainability', 'tests', 'performance'];

    /** A high finding left open this long is worth pointing at. */
    public const STALE_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant, int $days): array
    {
        $since = now()->subDays($days);
        $runs = $this->runs($tenant, $since, now());
        $previous = $this->runs($tenant, $since->subDays($days), $since, ['id'])->count();
        $repositories = $tenant->repositories()->with('integration')->withCount('openPullRequests')->orderBy('slug')->get();
        $verdicts = $this->feedback->forRuns($runs);

        return [
            'attention' => $this->attention($tenant, $repositories, $runs),
            'figures' => $this->figures($tenant, $runs, $previous, $verdicts, $days),
            'days' => $this->perDay($runs, $days, $tenant),
            'categories' => $this->categories($runs, $verdicts),
            'outcomes' => $this->outcomes->for($runs),
            'repositories' => $this->repositories($repositories, $runs, $verdicts),
            'activity' => $tenant->reviewRuns()->whereIn('status', self::REVIEWS)->latest('started_at')->limit(5)->get(),
            'running' => $runs->where('status', ReviewRun::STATUS_RUNNING)->count(),
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, ReviewRun>
     */
    protected function runs(Tenant $tenant, CarbonInterface $from, CarbonInterface $to, array $columns = ['*']): Collection
    {
        return $tenant->reviewRuns()
            ->whereIn('status', self::REVIEWS)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->get($columns);
    }

    /**
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array{rated: int, accepted: int, percent: int|null}
     */
    protected function acceptance(array $verdicts): array
    {
        $all = array_merge(...array_values($verdicts) ?: [[]]);
        $accepted = count(array_filter($all, fn (array $verdict): bool => $verdict['fair']));

        return ['rated' => count($all), 'accepted' => $accepted, 'percent' => $all === [] ? null : (int) round($accepted / count($all) * 100)];
    }

    /**
     * @param  Collection<int, Repository>  $repositories
     * @param  Collection<int, ReviewRun>  $runs
     * @return array<int, array{tag: string, tone: string, title: string, body: string, action: string, target: string}>
     */
    protected function attention(Tenant $tenant, Collection $repositories, Collection $runs): array
    {
        $items = [];
        $kinds = $tenant->connections()->pluck('kind')->map(fn (ConnectionKind|string $kind): string => $kind instanceof ConnectionKind ? $kind->value : $kind)->unique();

        foreach ([ConnectionKind::SourceControl, ConnectionKind::Llm] as $needed) {
            if (! $kinds->contains($needed->value)) {
                $items[] = [
                    'tag' => __('Setup'), 'tone' => 'danger',
                    'title' => $needed === ConnectionKind::Llm ? __('No AI provider yet') : __('No source control connection yet'),
                    'body' => $needed === ConnectionKind::Llm
                        ? __('A review needs an AI provider to run on. Without one no review can run.')
                        : __('Repositories are read and commented on through a source control connection.'),
                    'action' => $needed === ConnectionKind::Llm ? __('Add an AI provider') : __('Add a source control connection'),
                    'target' => $needed === ConnectionKind::Llm ? 'ai-providers' : 'source-control',
                ];
            }
        }

        $unable = $repositories->filter(fn (Repository $repository): bool => ! $repository->runner()->available());

        if ($unable->isNotEmpty()) {
            $items[] = [
                'tag' => __('Setup'), 'tone' => 'danger',
                'title' => $unable->count() === 1 ? __(':repository cannot start reviews', ['repository' => $unable->first()->slug]) : __(':count repositories cannot start reviews', ['count' => $unable->count()]),
                'body' => __('The runner it runs on is turned off on this installation. Its pull requests are not reviewed.'),
                'action' => __('Fix setup'), 'target' => 'repositories',
            ];
        }

        $stale = $this->staleHighFindings($tenant);

        if ($stale > 0) {
            $items[] = [
                'tag' => __('Findings'), 'tone' => 'danger',
                'title' => $stale === 1
                    ? __(':count high finding open for over :days days', ['count' => $stale, 'days' => self::STALE_DAYS])
                    : __(':count high findings open for over :days days', ['count' => $stale, 'days' => self::STALE_DAYS]),
                'body' => __('On pull requests whose last review requested changes and that were not reviewed since.'),
                'action' => __('Review findings'), 'target' => 'action',
            ];
        }

        $failed = $runs->whereIn('status', [ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT])->whereNull('dismissed_at');

        if ($failed->isNotEmpty()) {
            $timeouts = $failed->where('status', ReviewRun::STATUS_TIMEOUT)->count();

            $items[] = [
                'tag' => __('Runs'), 'tone' => 'neutral',
                'title' => $failed->count() === 1 ? __(':count review did not finish', ['count' => 1]) : __(':count reviews did not finish', ['count' => $failed->count()]),
                'body' => $timeouts > 0 ? __(':count of them were stopped after an hour without a result.', ['count' => $timeouts]) : __('They started, and stopped before they reported a result.'),
                'action' => __('See the reviews that did not finish'), 'target' => 'failed',
            ];
        }

        return $items;
    }

    /**
     * High findings still open on the last review of a pull request, where that
     * review is older than a week.
     */
    protected function staleHighFindings(Tenant $tenant): int
    {
        return $tenant->reviewRuns()
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->whereNotNull('pull_request_id')
            ->where('started_at', '>=', now()->subDays(90))
            ->orderBy('started_at')
            ->get(['id', 'repository_id', 'pull_request_id', 'result', 'findings', 'started_at'])
            ->keyBy(fn (ReviewRun $run): string => "{$run->repository_id}:{$run->pull_request_id}")
            ->filter(fn (ReviewRun $run): bool => $run->started_at->lt(now()->subDays(self::STALE_DAYS)))
            ->sum(fn (ReviewRun $run): int => RunPresenter::openBySeverity($run)['high'] ?? 0);
    }

    /**
     * @param  Collection<int, ReviewRun>  $runs
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array<int, array{label: string, value: string, note: string, tone: string, bar: int|null}>
     */
    protected function figures(Tenant $tenant, Collection $runs, int $previous, array $verdicts, int $days): array
    {
        $finished = $runs->where('status', ReviewRun::STATUS_FINISHED);
        $blocked = $finished->filter(fn (ReviewRun $run): bool => strtoupper((string) $run->result) === 'FAILED')->count();
        $durations = $finished->pluck('duration_seconds')->filter()->sort()->values();
        $failed = $runs->whereIn('status', [ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT])->count();
        $acceptance = $this->acceptance($verdicts);
        // The budget is in the tenant's currency, so the month is compared in it too.
        $month = $this->currency->convert((float) $tenant->reviewRuns()->where('started_at', '>=', now()->startOfMonth())->sum('cost'), $tenant);
        // What the sandboxes cost on top of the AI providers, where a review ran in one that is paid for.
        $sandboxes = $this->currency->convert((float) $tenant->reviewRuns()->where('started_at', '>=', now()->startOfMonth())->sum('sandbox_cost'), $tenant);
        $budget = $tenant->monthly_budget === null ? null : (float) $tenant->monthly_budget;
        $spent = $budget !== null && $budget > 0 ? (int) round($month / $budget * 100) : null;

        return [
            [
                'label' => __('Reviews'), 'value' => (string) $runs->count(), 'tone' => 'neutral', 'bar' => null,
                'note' => self::comparedTo($runs->count(), $previous, $days),
            ],
            [
                'label' => __('Changes requested'), 'tone' => 'neutral', 'bar' => null,
                'value' => $finished->isEmpty() ? '—' : round($blocked / $finished->count() * 100).'%',
                'note' => $finished->isEmpty() ? __('No finished reviews yet') : __(':count of :total finished', ['count' => $blocked, 'total' => $finished->count()]),
            ],
            [
                'label' => __('Findings accepted'), 'tone' => 'primary', 'bar' => null,
                'value' => $acceptance['percent'] === null ? '—' : "{$acceptance['percent']}%",
                'note' => $acceptance['rated'] === 0 ? __('No findings rated yet') : __(':count of :total rated findings', ['count' => $acceptance['accepted'], 'total' => $acceptance['rated']]),
            ],
            [
                'label' => __('Median duration'), 'tone' => 'neutral', 'bar' => null,
                'value' => $durations->isEmpty() ? '—' : (string) RunPresenter::duration((int) $durations[intdiv($durations->count(), 2)]),
                'note' => $durations->isEmpty() ? __('No finished reviews yet') : 'p90 '.RunPresenter::duration((int) $durations[(int) ceil($durations->count() * 0.9) - 1]),
            ],
            [
                'label' => __('Cost this month'), 'value' => Currency::showOwn($month, $tenant), 'tone' => $spent !== null && $spent > 85 ? 'danger' : 'neutral',
                'note' => ($spent === null ? __('No budget set') : __(':percent% of :budget budget', ['percent' => $spent, 'budget' => Currency::showOwn($budget, $tenant, 0)]))
                    .($sandboxes > 0 ? ' · '.__('sandboxes :cost', ['cost' => Currency::showOwn($sandboxes, $tenant)]) : ''),
                'bar' => $spent === null ? null : min(100, $spent),
            ],
            [
                'label' => __('Did not finish'), 'value' => (string) $failed, 'tone' => $failed > 0 ? 'danger' : 'neutral', 'bar' => null,
                'note' => $runs->isEmpty() ? __('No reviews yet') : __(':percent% of runs', ['percent' => round($failed / $runs->count() * 100, 1)]),
            ],
        ];
    }

    /**
     * How the number of reviews compares to the period before.
     */
    protected static function comparedTo(int $count, int $previous, int $days): string
    {
        return $previous === 0
            ? __('None in the :days days before', ['days' => $days])
            : __(':change% vs previous :period', ['change' => sprintf('%+d', (int) round(($count - $previous) / $previous * 100)), 'period' => "{$days}d"]);
    }

    /**
     * @param  Collection<int, ReviewRun>  $runs
     * @return array{bars: array<int, array{date: string, approved: int, changes: int}>, max: int}
     */
    protected function perDay(Collection $runs, int $days, Tenant $tenant): array
    {
        // A day is the tenant's day: a review at half past midnight in the tenant's
        // time zone belongs to that day, not to the one before it in UTC.
        $finished = $runs->where('status', ReviewRun::STATUS_FINISHED)->groupBy(fn (ReviewRun $run): string => LocalTime::of($run->started_at, $tenant)->toDateString());
        $bars = [];
        $today = LocalTime::now($tenant);

        for ($day = $today->subDays($days - 1)->startOfDay(); $day->lte($today); $day = $day->addDay()) {
            $ofDay = $finished[$day->toDateString()] ?? collect();
            $changes = $ofDay->filter(fn (ReviewRun $run): bool => strtoupper((string) $run->result) === 'FAILED')->count();

            $bars[] = ['date' => $day->toDateString(), 'approved' => $ofDay->count() - $changes, 'changes' => $changes];
        }

        return ['bars' => $bars, 'max' => max(1, ...array_map(fn (array $bar): int => $bar['approved'] + $bar['changes'], $bars))];
    }

    /**
     * Findings as they were first reported, so one carried through five runs counts
     * once.
     *
     * @param  Collection<int, ReviewRun>  $runs
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array<int, array{name: string, findings: int, rated: int, accepted: int}>
     */
    protected function categories(Collection $runs, array $verdicts): array
    {
        $found = array_fill_keys(self::CATEGORIES, 0);

        foreach ($runs as $run) {
            foreach ($run->findings ?? [] as $finding) {
                $category = strtolower((string) ($finding['category'] ?? ''));

                if (($finding['status'] ?? null) === 'new' && isset($found[$category])) {
                    $found[$category]++;
                }
            }
        }

        $all = collect(array_merge(...array_values($verdicts) ?: [[]]))->groupBy('category');

        return array_map(fn (string $category): array => [
            'name' => ReviewSettingsForm::categoryLabel($category),
            'findings' => $found[$category],
            'rated' => ($all[$category] ?? collect())->count(),
            'accepted' => ($all[$category] ?? collect())->where('fair', true)->count(),
        ], self::CATEGORIES);
    }

    /**
     * @param  Collection<int, Repository>  $repositories
     * @param  Collection<int, ReviewRun>  $runs
     * @param  array<int, array<int, array{category: string, fair: bool}>>  $verdicts
     * @return array<int, array{repository: Repository, ready: bool, open: int, reviews: int, per_pull_request: string|null, rejected_per_pull_request: string|null, changes: string, accepted: string, cost: float}>
     */
    protected function repositories(Collection $repositories, Collection $runs, array $verdicts): array
    {
        $byRepository = $runs->groupBy('repository_id');

        return $repositories->map(function (Repository $repository) use ($byRepository, $verdicts): array {
            $own = $byRepository[$repository->getKey()] ?? collect();
            $finished = $own->where('status', ReviewRun::STATUS_FINISHED);
            $blocked = $finished->filter(fn (ReviewRun $run): bool => strtoupper((string) $run->result) === 'FAILED')->count();
            $acceptance = $this->acceptance(array_intersect_key($verdicts, $own->keyBy('id')->all()));
            // How many rounds a pull request took, and in how many of them the review asked for changes: trials are no round.
            $rounds = $own->filter(fn (ReviewRun $run): bool => $run->pull_request_id !== null && ! $run->custom);
            $pullRequests = $rounds->pluck('pull_request_id')->unique()->count();
            $rejected = $rounds->filter(fn (ReviewRun $run): bool => $run->status === ReviewRun::STATUS_FINISHED && strtoupper((string) $run->result) === 'FAILED')->count();

            return [
                'repository' => $repository,
                'ready' => $repository->runner()->available(),
                'open' => (int) $repository->getAttribute('open_pull_requests_count'),
                'reviews' => $own->count(),
                'per_pull_request' => $pullRequests === 0 ? null : number_format($rounds->count() / $pullRequests, 1),
                'rejected_per_pull_request' => $pullRequests === 0 ? null : number_format($rejected / $pullRequests, 1),
                'changes' => $finished->isEmpty() ? '—' : round($blocked / $finished->count() * 100).'%',
                'accepted' => $acceptance['percent'] === null ? '—' : "{$acceptance['percent']}%",
                'cost' => (float) $own->sum(fn (ReviewRun $run): float => (float) $run->cost),
            ];
        })->sortByDesc('reviews')->values()->all();
    }
}
