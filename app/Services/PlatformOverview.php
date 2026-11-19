<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every tenant at a glance, for the installation's administrator.
 */
class PlatformOverview
{
    public function __construct(protected FindingFeedback $feedback, protected Currency $currency) {}

    /** Share of the monthly budget from which a tenant is near it. */
    public const NEAR_BUDGET_PERCENT = 85;

    /**
     * @return array{figures: array<int, array{label: string, value: string, note: string, tone: string}>, tenants: array<int, array<string, mixed>>}
     */
    public function for(CarbonInterface $since): array
    {
        $runs = ReviewRun::query()
            ->whereIn('status', TenantOverview::REVIEWS)
            ->where('started_at', '>=', $since)
            ->get(['id', 'tenant_id', 'status', 'cost', 'findings']);

        $monthly = ReviewRun::query()->where('started_at', '>=', now()->startOfMonth())
            ->selectRaw('tenant_id, sum(cost) as cost')->groupBy('tenant_id')->pluck('cost', 'tenant_id');

        $rated = $this->acceptance($runs);

        $tenants = Tenant::query()
            ->withCount(['users', 'repositories'])
            ->with(['connections:id,tenant_id,driver,kind', 'repositories:id,tenant_id,connection_id,review_runner'])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($runs, $monthly, $rated): array {
                $own = $runs->where('tenant_id', $tenant->getKey());
                // In the tenant's currency, as its budget is.
                $spent = $this->currency->convert((float) ($monthly[$tenant->getKey()] ?? 0), $tenant);
                $budget = $tenant->monthly_budget === null ? null : (float) $tenant->monthly_budget;
                $percent = $budget !== null && $budget > 0 ? (int) round($spent / $budget * 100) : null;
                $blocked = $tenant->repositories->filter(fn (Repository $repository): bool => ! $repository->runner()->available())->count();
                $verdicts = $rated[$tenant->getKey()] ?? ['rated' => 0, 'accepted' => 0];

                return [
                    'tenant' => $tenant,
                    'connections' => $tenant->connections->map(fn (Connection $connection): string => rescue(fn (): string => $connection->driver()->label(), ucfirst($connection->driver), false))->unique()->implode(' · ') ?: '—',
                    'reviews' => $own->count(),
                    'accepted' => $verdicts['rated'] === 0 ? '—' : round($verdicts['accepted'] / $verdicts['rated'] * 100).'%',
                    'spent' => $spent,
                    'budget' => $budget,
                    'money' => Currency::showOwn($spent, $tenant).($budget === null ? '' : ' of '.Currency::showOwn($budget, $tenant, 0)),
                    'percent' => $percent,
                    'health' => match (true) {
                        $tenant->repositories_count === 0 => ['label' => 'Not set up', 'tone' => 'neutral'],
                        $percent !== null && $percent >= self::NEAR_BUDGET_PERCENT => ['label' => $percent >= 100 ? 'Over budget' : 'Near budget', 'tone' => 'failed'],
                        $blocked > 0 => ['label' => $blocked === 1 ? '1 repo blocked' : "{$blocked} repos blocked", 'tone' => 'changes'],
                        default => ['label' => 'Healthy', 'tone' => 'approved'],
                    },
                ];
            })->all();

        $failed = $runs->whereIn('status', [ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT])->count();
        $cost = (float) $runs->sum(fn (ReviewRun $run): float => (float) $run->cost);
        $allRated = array_sum(array_column($rated, 'rated'));
        $active = count(array_filter($tenants, fn (array $row): bool => $row['reviews'] > 0));
        $idle = count($tenants) - $active;

        return [
            'figures' => [
                ['label' => 'Active tenants', 'value' => (string) $active, 'note' => $idle === 0 ? 'Every tenant ran a review' : "{$idle} without a review", 'tone' => 'neutral'],
                ['label' => 'Reviews', 'value' => number_format($runs->count()), 'note' => 'Across all tenants', 'tone' => 'neutral'],
                ['label' => 'Model cost', 'value' => Currency::show($cost), 'note' => $runs->isEmpty() ? 'No reviews yet' : Currency::show($cost / $runs->count()).' per review', 'tone' => 'neutral'],
                ['label' => 'Did not finish', 'value' => $runs->isEmpty() ? '—' : round($failed / $runs->count() * 100, 1).'%', 'note' => "{$failed} ".($failed === 1 ? 'run' : 'runs'), 'tone' => $failed > 0 ? 'danger' : 'neutral'],
                ['label' => 'Findings accepted', 'value' => $allRated === 0 ? '—' : round(array_sum(array_column($rated, 'accepted')) / $allRated * 100).'%', 'note' => $allRated === 0 ? 'No findings rated yet' : "Of {$allRated} rated findings", 'tone' => 'primary'],
            ],
            'tenants' => $tenants,
        ];
    }

    /**
     * How many findings people gave an opinion on, and how many they accepted, per tenant.
     *
     * @param  Collection<int, ReviewRun>  $runs
     * @return array<int, array{rated: int, accepted: int}>
     */
    protected function acceptance(Collection $runs): array
    {
        $tenantOfRun = $runs->pluck('tenant_id', 'id');
        $perTenant = [];

        foreach ($this->feedback->forRuns($runs) as $run => $opinions) {
            $tenant = $tenantOfRun[$run];
            $perTenant[$tenant] ??= ['rated' => 0, 'accepted' => 0];
            $perTenant[$tenant]['rated'] += count($opinions);
            $perTenant[$tenant]['accepted'] += count(array_filter($opinions, fn (array $opinion): bool => $opinion['fair']));
        }

        return $perTenant;
    }
}
