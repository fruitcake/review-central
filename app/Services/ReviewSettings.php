<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewEffort;
use App\Enums\ReviewProvider;
use App\Models\Repository;
use App\Models\ReviewSetting;
use App\Models\Tenant;
use BackedEnum;

/**
 * Which reviewer the AI review runs, and at what model and effort, as the server keeps
 * them: a default for every repository, and a repository's own values over it.
 *
 * The run reads them as the repository variables of the same name, and only
 * where that variable is not set: a variable in Bitbucket still wins, so a
 * repository that sets its own keeps it.
 */
class ReviewSettings
{
    /**
     * Each setting under the variable the scripts read it from. REVIEW_PROVIDER is
     * not among them: variables() picks it from the providers list.
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'claude_model' => 'CLAUDE_REVIEW_MODEL',
        'claude_effort' => 'CLAUDE_REVIEW_EFFORT',
        'claude_advisor' => 'CLAUDE_REVIEW_ADVISOR',
        'claude_max_budget' => 'CLAUDE_REVIEW_MAX_BUDGET',
        'codex_model' => 'CODEX_REVIEW_MODEL',
        'codex_effort' => 'CODEX_REVIEW_EFFORT',
        'ocr_model' => 'OCR_REVIEW_MODEL',
        'ocr_effort' => 'OCR_REVIEW_EFFORT',
        'verify' => 'REVIEW_VERIFY',
        'language' => 'REVIEW_LANGUAGE',
        'ticket_grading' => 'REVIEW_TICKET_GRADING',
        'extra_passes' => 'REVIEW_EXTRA_PASSES',
    ];

    /** The languages a review can write in, by code, as the reviewer is told them. */
    public const LANGUAGES = [
        'en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
        'it' => 'Italian', 'pt' => 'Portuguese', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
    ];

    /**
     * What the review's own text leaves to the team, as the reviewer reads it: the
     * language of what it writes for people, whether it grades the change against
     * the ticket, and whether it runs the extra passes that cost more.
     *
     * @param  array<string, string>  $variables  as variables() gives them
     * @return array{language: string, grade_ticket: bool, extra_passes: bool}
     */
    public static function options(array $variables): array
    {
        return [
            'language' => self::LANGUAGES[$variables['REVIEW_LANGUAGE'] ?? 'en'] ?? self::LANGUAGES['en'],
            'grade_ticket' => ($variables['REVIEW_TICKET_GRADING'] ?? 'true') !== 'false',
            // Off unless asked: they read the change again from scratch, at several times the cost.
            'extra_passes' => ($variables['REVIEW_EXTRA_PASSES'] ?? 'false') === 'true',
        ];
    }

    /** The most reviews one container job carries on with after its own. */
    public const MAX_FOLLOW_UPS = 2;

    /**
     * How many reviews a container job may carry on with when the pull request
     * got a new commit meanwhile: the repository's own number, or the tenant's.
     */
    public static function followUps(Repository $repository): int
    {
        $own = $repository->reviewSetting->follow_ups ?? self::defaults($repository->tenant)->follow_ups;

        return max(0, min(self::MAX_FOLLOW_UPS, (int) $own));
    }

    /**
     * Saves a tenant's defaults.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveDefaults(Tenant $tenant, array $data): void
    {
        $setting = self::defaults($tenant);
        $setting->fill(array_intersect_key($data, self::VARIABLES + ['providers' => true, 'follow_ups' => true]));
        $setting->save();
    }

    /**
     * The defaults for every repository of a tenant; an unsaved, empty row until someone sets one.
     */
    public static function defaults(Tenant $tenant): ReviewSetting
    {
        return ReviewSetting::query()->where('tenant_id', $tenant->getKey())->whereNull('repository_id')->firstOrNew(['tenant_id' => $tenant->getKey()]);
    }

    /**
     * Saves a repository's own settings. With none left it has no row, and reads the
     * defaults throughout.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveFor(Repository $repository, array $data): void
    {
        $data = array_intersect_key($data, self::VARIABLES + ['providers' => true, 'follow_ups' => true]);

        if (array_filter($data, fn (mixed $value): bool => filled($value)) === []) {
            $repository->reviewSetting()->delete();

            return;
        }

        $repository->reviewSetting()->updateOrCreate([], $data + ['tenant_id' => $repository->tenant_id]);
    }

    /**
     * Keeps the reviewers the run says it can run in this repository, for the
     * admin to see.
     *
     * @param  array<int, string>  $available
     */
    public static function recordAvailable(Repository $repository, array $available): void
    {
        $repository->review_available_providers = $available;
        $repository->review_available_at = now();
        $repository->save();
    }

    /**
     * The reviewers a setting lists, in order, each with its share. A row from before
     * the list has its single provider at a full share; a row saved with an empty
     * list has none.
     *
     * @return array<int, array{provider: ReviewProvider, share: int, budget: float|null}>
     */
    public static function providers(?ReviewSetting $setting): array
    {
        if ($setting?->providers === null) {
            return [];
        }

        $entries = [];

        foreach ($setting->providers as $entry) {
            $provider = ReviewProvider::tryFrom($entry['provider']);

            if ($provider !== null) {
                $entries[] = ['provider' => $provider, 'share' => max(0, $entry['share']), 'budget' => is_numeric($entry['budget'] ?? null) ? (float) $entry['budget'] : null];
            }
        }

        return $entries;
    }

    /**
     * What each reviewer's reviews have cost the tenant this month, in dollars, by
     * the name the run records it under.
     *
     * @return array<string, float>
     */
    public static function spentThisMonth(Tenant $tenant): array
    {
        return $tenant->reviewRuns()
            ->where('started_at', '>=', now()->startOfMonth())
            ->whereNotNull('agent')
            ->selectRaw('agent, sum(cost) as spent')
            ->groupBy('agent')
            ->pluck('spent', 'agent')
            ->map(fn (mixed $spent): float => (float) $spent)
            ->all();
    }

    /**
     * The reviewers whose month has cost their budget. The budget is in the tenant's
     * currency and the costs in dollars, so the month is converted first.
     *
     * @param  array<int, array{provider: ReviewProvider, share: int, budget: float|null}>  $entries
     * @return array<int, string>
     */
    private static function overBudget(Tenant $tenant, array $entries): array
    {
        $budgeted = array_filter($entries, fn (array $entry): bool => $entry['budget'] !== null);

        if ($budgeted === []) {
            return [];
        }

        $spent = self::spentThisMonth($tenant);
        $currency = app(Currency::class);

        return array_values(array_map(
            fn (array $entry): string => $entry['provider']->value,
            array_filter($budgeted, fn (array $entry): bool => $currency->convert($spent[$entry['provider']->value] ?? 0.0, $tenant) >= $entry['budget']),
        ));
    }

    /**
     * What a repository's review runs with, by variable: its own value where
     * it has one, the default otherwise. A setting that neither has is left out, so
     * the run decides it as it would without the server.
     *
     * @param  array<int, string>|null  $available  the reviewer names the run can run, null when it did not say
     * @param  string|null  $pullRequest  the pull request id, so each one keeps its reviewer
     * @param  string|null  $branch  the branch, for when the run knows no pull request
     * @param  ReviewEffort|null  $effort  the classified effort, which replaces every reviewer's set one
     * @return array<string, string>
     */
    public static function variables(Repository $repository, ?array $available = null, ?string $pullRequest = null, ?string $branch = null, ?ReviewEffort $effort = null): array
    {
        $own = $repository->reviewSetting;
        $default = self::defaults($repository->tenant);

        $variables = [];

        $key = match (true) {
            filled($pullRequest) => "{$repository->getKey()}:{$pullRequest}",
            filled($branch) => "{$repository->getKey()}:{$branch}",
            default => null,
        };

        // The repository's own list, or the defaults' when it has none.
        $entries = self::providers($own) ?: self::providers($default);
        $provider = self::choose($entries, $available, $key, self::overBudget($repository->tenant, $entries));

        if ($provider !== null) {
            $variables['REVIEW_PROVIDER'] = $provider->value;
        }

        foreach (self::VARIABLES as $field => $variable) {
            $value = $effort !== null && str_ends_with($field, '_effort') ? $effort : (filled($own?->{$field}) ? $own->{$field} : $default->{$field});

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if (is_string($value) && trim($value) !== '') {
                $variables[$variable] = trim($value);
            }
        }

        return $variables;
    }

    /**
     * Picks a reviewer the run can run, by share, from the stable key: the same
     * pull request gets the same reviewer for as long as the list and what is
     * available stay the same. Without a key (a run from before it sent one)
     * the first. A share of 0 only runs when nothing with a share can, in list order.
     *
     * A null $available means the run did not say, and everything counts as
     * available; a name it sends that is no ReviewProvider matches nothing.
     *
     * A reviewer over its monthly budget is passed over, which moves the pull
     * requests it had to the others. When that leaves nobody, the budgets are let
     * go: a review that costs more than was meant beats no review.
     *
     * @param  array<int, array{provider: ReviewProvider, share: int, budget: float|null}>  $entries
     * @param  array<int, string>|null  $available
     * @param  array<int, string>  $overBudget  the reviewers whose month has cost their budget
     */
    private static function choose(array $entries, ?array $available, ?string $key, array $overBudget = []): ?ReviewProvider
    {
        $runnable = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $available === null || in_array($entry['provider']->value, $available, true),
        ));

        $within = array_values(array_filter($runnable, fn (array $entry): bool => ! in_array($entry['provider']->value, $overBudget, true)));
        $runnable = $within ?: $runnable;

        $candidates = array_values(array_filter($runnable, fn (array $entry): bool => $entry['share'] > 0));

        if ($candidates !== [] && $key !== null) {
            $point = crc32($key) % array_sum(array_column($candidates, 'share'));

            foreach ($candidates as $candidate) {
                if ($point < $candidate['share']) {
                    return $candidate['provider'];
                }

                $point -= $candidate['share'];
            }
        }

        $first = $candidates[0] ?? $runnable[0] ?? null;

        return $first['provider'] ?? null;
    }
}
