<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the reviews of one pull request may cost, so a pull request pushed to over
 * and over does not run up the team's bill one review at a time. Past it, a push
 * starts no review; a `/review` comment still does, for one review.
 *
 * The budget is in the tenant's currency, and the costs in dollars, so what was
 * spent is converted first. Trials are not the pull request's review and do not count.
 */
class PullRequestBudget
{
    public function __construct(protected Currency $currency) {}

    /**
     * The repository's own budget, or the team's; null for none.
     */
    public function budget(Repository $repository): ?float
    {
        $budget = $repository->reviewSetting->pull_request_budget ?? ReviewSettings::defaults($repository->tenant)->pull_request_budget;

        return is_numeric($budget) && (float) $budget > 0 ? (float) $budget : null;
    }

    /**
     * What the reviews of the pull request have cost so far, in the tenant's currency.
     */
    public function spent(Repository $repository, int $pullRequestId): float
    {
        $dollars = (float) $repository->reviewRuns()
            ->where('pull_request_id', $pullRequestId)
            ->where('custom', false)
            ->selectRaw('sum(coalesce(cost, 0) + coalesce(sandbox_cost, 0)) as spent')
            ->value('spent');

        return $this->currency->convert($dollars, $repository->tenant);
    }

    /**
     * Whether the pull request's reviews have cost its budget.
     */
    public function isSpent(Repository $repository, int $pullRequestId): bool
    {
        $budget = $this->budget($repository);

        return $budget !== null && $this->spent($repository, $pullRequestId) >= $budget;
    }

    /**
     * Says so on the pull request, once: otherwise a push that starts nothing
     * looks like a review that broke. Best effort; the skip stands either way.
     */
    public function tell(Repository $repository, int $pullRequestId): void
    {
        if (! Cache::add("pull-request-budget-told:{$repository->getKey()}:{$pullRequestId}", true, now()->addDays(30))) {
            return;
        }

        try {
            if (! $repository->sourceControl()->publishes()) {
                return;
            }

            $repository->sourceControl()->addPullRequestComment((string) $pullRequestId, sprintf(
                'The AI reviews of this pull request have cost %s of its %s budget, so a push no longer starts one. Comment `%s` to have it reviewed once more.',
                Currency::showOwn($this->spent($repository, $pullRequestId), $repository->tenant),
                Currency::showOwn((float) $this->budget($repository), $repository->tenant),
                ReviewRequest::COMMAND,
            )."\n\n".ReviewPublisher::REPLY_MARKER);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
