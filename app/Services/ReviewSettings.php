<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewComplexity;
use App\Enums\ReviewEffort;
use App\Enums\ReviewProvider;
use App\Enums\ReviewStrategy;
use App\Models\Repository;
use App\Models\ReviewSetting;
use App\Models\Tenant;
use BackedEnum;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;

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
     * A model can be named by what it is for rather than by its ID: the provider's
     * smartest, default or cheapest, as the Laravel AI SDK names them when the
     * review runs, so the setting follows the SDK as it moves on.
     */
    public const TIERS = ['smartest', 'default', 'cheapest'];

    /** The model settings a tier is read for, with the SDK's driver it is read from. */
    protected const TIERED = ['claude_model' => 'anthropic', 'claude_advisor' => 'anthropic', 'codex_model' => 'openai'];

    /**
     * The SDK's smartest, default and cheapest model of a provider, by tier; none
     * where the SDK has no such provider.
     *
     * @return array<string, string>
     */
    public static function tierModels(string $driver): array
    {
        return once(fn (): array => rescue(function () use ($driver): array {
            $provider = Ai::build(['name' => "model-tiers-{$driver}", 'driver' => $driver, 'key' => '']);

            return $provider instanceof TextProvider
                ? ['smartest' => $provider->smartestTextModel(), 'default' => $provider->defaultTextModel(), 'cheapest' => $provider->cheapestTextModel()]
                : [];
        }, [], false));
    }

    /**
     * The model a setting names: a tier as the SDK reads it now, anything else as
     * it is.
     */
    public static function model(string $driver, string $model): string
    {
        return in_array($model, self::TIERS, true) ? (self::tierModels($driver)[$model] ?? $model) : $model;
    }

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
        'ocr' => 'REVIEW_OCR',
        'scan' => 'REVIEW_SCAN',
        'floor_guard' => 'REVIEW_FLOOR_GUARD',
    ];

    /**
     * The parts of a review that can each be turned off, by setting, with what each
     * is when nothing sets it. Null leaves it to the reviewer: see modules().
     *
     * Claude Code, Codex and Pi follow SKILL.md, and these are what it is built from
     * besides the reviewer itself. All of them off is the reviewer asked for a review
     * and nothing else. OpenCodeReview's own review has none of them.
     *
     * @var array<string, bool|null>
     */
    public const MODULES = [
        'ocr' => true,
        'scan' => true,
        'floor_guard' => true,
        'extra_passes' => null,
        'verify' => false,
    ];

    /** What each module is called where a person reads it. */
    public const MODULE_LABELS = [
        'ocr' => 'OCR',
        'scan' => 'scan',
        'floor_guard' => 'floor guard',
        'extra_passes' => 'extra passes',
        'verify' => 'second check',
    ];

    /**
     * Which modules a review with these variables runs, for the reviewer it runs
     * with. A module the reviewer has not got is off; null for a reviewer that
     * follows no SKILL.md.
     *
     * @param  array<string, string>  $variables  as variables() gives them
     * @return array<string, bool>|null
     */
    public static function modules(ReviewProvider $reviewer, array $variables): ?array
    {
        if (! in_array($reviewer, [ReviewProvider::ClaudeCode, ReviewProvider::CodexCli, ReviewProvider::Pi], true)) {
            return null;
        }

        $modules = [];

        foreach (self::MODULES as $field => $default) {
            $modules[$field] = match ($variables[self::VARIABLES[$field]] ?? null) {
                'true' => true,
                'false' => false,
                // Claude Code's own passes cost several times a review; Codex's one is cheap.
                default => $default ?? $reviewer === ReviewProvider::CodexCli,
            };
        }

        // Pi has no review pass of its own and no second check.
        if ($reviewer === ReviewProvider::Pi) {
            $modules['extra_passes'] = false;
            $modules['verify'] = false;
        }

        return $modules;
    }

    /**
     * The modules a run had, as a person reads them: those on, then those off. A
     * trial's own may name only some of them.
     *
     * @param  array<string, bool>  $modules
     */
    public static function describeModules(array $modules): string
    {
        $on = array_keys(array_filter($modules));
        $off = array_keys(array_filter($modules, fn (bool $value): bool => ! $value));
        $label = fn (array $fields): string => implode(', ', array_map(fn (string $field): string => self::MODULE_LABELS[$field] ?? $field, $fields));

        if ($on === [] && array_diff_key(self::MODULES, $modules) === []) {
            return 'plain: the reviewer alone';
        }

        return implode('; ', array_filter([
            $on === [] ? null : $label($on),
            $off === [] ? null : 'without '.$label($off),
        ]));
    }

    /**
     * Sets every module a trial names in the variables, over what the repository
     * would run.
     *
     * @param  array<string, string>  $variables
     * @param  array<string, bool>|null  $modules
     * @return array<string, string>
     */
    public static function withModules(array $variables, ?array $modules): array
    {
        foreach ($modules ?? [] as $field => $on) {
            if (array_key_exists($field, self::MODULES)) {
                $variables[self::VARIABLES[$field]] = $on ? 'true' : 'false';
            }
        }

        return $variables;
    }

    /** The settings of classifying pull requests, which the server applies and no script reads. */
    public const STRATEGY_FIELDS = ['strategy' => true, 'strategy_effort' => true, 'strategy_model' => true];

    /** The languages a review can write in, by code, as the reviewer is told them. */
    public const LANGUAGES = [
        'en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
        'it' => 'Italian', 'pt' => 'Portuguese', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish',
    ];

    /**
     * What the review's own text leaves to the team, as the reviewer reads it: the
     * language of what it writes for people, whether it grades the change against
     * the ticket, whether it runs the extra passes that cost more, and whether it
     * runs OCR and the floor guard and reads the scan.
     *
     * @param  array<string, string>  $variables  as variables() gives them
     * @return array{language: string, grade_ticket: bool, extra_passes: bool, ocr: bool, scan: bool, floor_guard: bool}
     */
    public static function options(array $variables): array
    {
        return [
            'language' => self::LANGUAGES[$variables['REVIEW_LANGUAGE'] ?? 'en'] ?? self::LANGUAGES['en'],
            'grade_ticket' => ($variables['REVIEW_TICKET_GRADING'] ?? 'true') !== 'false',
            // Off unless asked: they read the change again from scratch, at several times the cost.
            'extra_passes' => ($variables['REVIEW_EXTRA_PASSES'] ?? 'false') === 'true',
            'ocr' => ($variables['REVIEW_OCR'] ?? 'true') !== 'false',
            'scan' => ($variables['REVIEW_SCAN'] ?? 'true') !== 'false',
            'floor_guard' => ($variables['REVIEW_FLOOR_GUARD'] ?? 'true') !== 'false',
        ];
    }

    /**
     * Whether the repository's settings ask the review to grade a change against its
     * ticket's acceptance criteria, as options() reads it: its own setting, or the
     * team's. A preference: a reviewer that writes no grading, such as OpenCodeReview,
     * leaves it out all the same.
     */
    public static function gradesTicket(Repository $repository): bool
    {
        $own = $repository->reviewSetting?->ticket_grading;

        return self::options(['REVIEW_TICKET_GRADING' => (string) (filled($own) ? $own : self::defaults($repository->tenant)->ticket_grading)])['grade_ticket'];
    }

    /**
     * The follow-up rounds a pull request's review may add findings in: the
     * repository's own number, or the team's, or five.
     */
    public static function maxRounds(Repository $repository): int
    {
        $own = $repository->reviewSetting->max_rounds ?? self::defaults($repository->tenant)->max_rounds;

        return max(0, (int) ($own ?? ReviewFollowUps::MAX_ROUNDS));
    }

    /** The thresholds a team can pick from, as `fail_on` names them. `low` is `any` by another name. */
    public const THRESHOLDS = ['never', 'any', 'medium', 'high'];

    /**
     * The severity from which a finding holds the merge, as a `fail_on` spec: the
     * platform's, with the team's over it, and the repository's over that, one
     * category at a time. Set only for every category, a level below still keeps
     * the categories it names on their own.
     */
    public static function failOn(Repository $repository): string
    {
        $thresholds = array_merge(
            self::thresholds(ReviewPublisher::DEFAULT_FAIL_ON),
            self::ownThresholds(self::defaults($repository->tenant)->fail_on),
            self::ownThresholds($repository->reviewSetting?->fail_on),
        );

        $spec = $thresholds['default'];

        foreach (ReviewPublisher::CATEGORIES as $category) {
            if (isset($thresholds[$category]) && $thresholds[$category] !== $thresholds['default']) {
                $spec .= ",{$category}:{$thresholds[$category]}";
            }
        }

        return ReviewPublisher::failOnSpec($spec);
    }

    /**
     * A `fail_on` spec by category, with `default` for every category it does not name.
     *
     * @return array<string, string>
     */
    public static function thresholds(string $failOn): array
    {
        $parts = explode(',', ReviewPublisher::failOnSpec($failOn));
        $thresholds = ['default' => $parts[0] === 'low' ? 'any' : $parts[0]];

        foreach (array_slice($parts, 1) as $part) {
            [$category, $threshold] = explode(':', $part, 2);
            $thresholds[$category] = $threshold === 'low' ? 'any' : $threshold;
        }

        return $thresholds;
    }

    /**
     * What a setting sets of the threshold, without what it leaves to the level
     * below and without anything that is no category or no threshold.
     *
     * @param  array<string, mixed>|null  $failOn
     * @return array<string, string>
     */
    public static function ownThresholds(?array $failOn): array
    {
        return array_filter(
            array_intersect_key($failOn ?? [], array_flip(['default', ...ReviewPublisher::CATEGORIES])),
            fn (mixed $threshold): bool => in_array($threshold, self::THRESHOLDS, true),
        );
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
        // The notes on whose turn are the team's alone, and no repository's.
        $setting->fill(array_intersect_key(self::withOwnThresholds($data), self::VARIABLES + self::STRATEGY_FIELDS + ['providers' => true, 'follow_ups' => true, 'max_rounds' => true, 'fail_on' => true, 'pull_request_budget' => true, 'turn_notes' => true, 'turn_model' => true, 'findings_check' => true]));
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
        $data = array_intersect_key(self::withOwnThresholds($data), self::VARIABLES + self::STRATEGY_FIELDS + ['providers' => true, 'follow_ups' => true, 'max_rounds' => true, 'fail_on' => true, 'pull_request_budget' => true]);

        if (array_filter($data, fn (mixed $value): bool => filled($value)) === []) {
            $repository->reviewSetting()->delete();

            return;
        }

        $repository->reviewSetting()->updateOrCreate([], $data + ['tenant_id' => $repository->tenant_id]);
    }

    /**
     * The form's thresholds as they are kept: only those set, and none at all as null,
     * so a setting that sets none inherits every one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function withOwnThresholds(array $data): array
    {
        if (array_key_exists('fail_on', $data)) {
            $data['fail_on'] = self::ownThresholds(is_array($data['fail_on']) ? $data['fail_on'] : null) ?: null;
        }

        return $data;
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
     * @param  array{effort: ReviewEffort, tier: string|null}|null  $pick  what the pull request's classification picked, which replaces every reviewer's effort and Claude Code's and Codex's model
     * @return array<string, string>
     */
    public static function variables(Repository $repository, ?array $available = null, ?string $pullRequest = null, ?string $branch = null, ?array $pick = null): array
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
            $value = match (true) {
                $pick !== null && str_ends_with($field, '_effort') => $pick['effort'],
                $pick !== null && $pick['tier'] !== null && in_array($field, self::PICKED_MODELS, true) => $pick['tier'],
                default => filled($own?->{$field}) ? $own->{$field} : $default->{$field},
            };

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if (is_string($value) && trim($value) !== '') {
                $variables[$variable] = isset(self::TIERED[$field]) ? self::model(self::TIERED[$field], trim($value)) : trim($value);
            }
        }

        // Pi has no effort setting of its own, only a trial's: the pick is its effort too.
        if ($pick !== null) {
            $variables['PI_REVIEW_EFFORT'] = $pick['effort']->value;
        }

        // For a reviewer with no default model of its own, OpenCodeReview and Pi: the
        // SDK's pick, Anthropic's smartest and OpenAI's default, read now.
        $variables += array_filter([
            'REVIEW_DEFAULT_MODEL_ANTHROPIC' => self::tierModels('anthropic')['smartest'] ?? null,
            'REVIEW_DEFAULT_MODEL_OPENAI' => self::tierModels('openai')['default'] ?? null,
        ]);

        return $variables;
    }

    /**
     * The models a classification picks a tier for: the reviewers the Laravel AI SDK
     * reads a tier for. OpenCodeReview passes a name on as it is.
     */
    public const PICKED_MODELS = ['claude_model', 'codex_model'];

    /** The lowest effort a classified pull request may start from, and where it starts when none is set. */
    public const STRATEGY_EFFORTS = [ReviewEffort::Low, ReviewEffort::Medium, ReviewEffort::High];

    public const STRATEGY_EFFORT = ReviewEffort::Medium;

    /** The model tiers from the lowest up; a classified pull request starts from default unless set. */
    public const STRATEGY_TIERS = ['cheapest', 'default', 'smartest'];

    public const STRATEGY_TIER = 'default';

    /** In place of a tier: the classification steps the effort up, and leaves each reviewer's model as set. */
    public const OWN_MODEL = 'own';

    /**
     * Whether a repository's pull requests are classified, and from which effort and
     * model tier up: its own setting where it has one, the team's otherwise.
     *
     * @return array{mode: ReviewStrategy, effort: ReviewEffort, tier: string} tier is a tier or OWN_MODEL
     */
    public static function strategy(Repository $repository): array
    {
        $own = $repository->reviewSetting;
        $default = self::defaults($repository->tenant);
        $value = fn (string $field): ?string => filled($own?->{$field}) ? (string) $own->{$field} : (filled($default->{$field}) ? (string) $default->{$field} : null);

        $effort = ReviewEffort::tryFrom((string) $value('strategy_effort'));
        $tier = $value('strategy_model');

        return [
            'mode' => ReviewStrategy::tryFrom((string) $value('strategy')) ?? ReviewStrategy::Off,
            'effort' => in_array($effort, self::STRATEGY_EFFORTS, true) ? $effort : self::STRATEGY_EFFORT,
            'tier' => in_array($tier, [...self::STRATEGY_TIERS, self::OWN_MODEL], true) ? $tier : self::STRATEGY_TIER,
        ];
    }

    /**
     * The effort and model tier for a complexity: the minimum for a simple pull
     * request, a step up for a normal one, two for a complex one, and no higher
     * than the top.
     *
     * @return array{effort: ReviewEffort, tier: string|null} no tier for OWN_MODEL: each reviewer keeps its own
     */
    public static function pick(ReviewEffort $effort, string $tier, ReviewComplexity $complexity): array
    {
        $efforts = ReviewEffort::cases();
        $from = (int) array_search($effort, $efforts, true);
        $tierFrom = (int) array_search($tier, self::STRATEGY_TIERS, true);

        return [
            'effort' => $efforts[min(count($efforts) - 1, $from + $complexity->steps())],
            'tier' => $tier === self::OWN_MODEL ? null : self::STRATEGY_TIERS[min(count(self::STRATEGY_TIERS) - 1, $tierFrom + $complexity->steps())],
        ];
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
