<?php

namespace App\Filament\Components;

use App\Enums\ReviewEffort;
use App\Enums\ReviewProvider;
use App\Filament\CurrentTenant;
use App\Models\ModelPrice;
use App\Models\ReviewSetting;
use App\Services\Currency;
use App\Services\ReviewCredentials;
use App\Services\ReviewSettings;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Which reviewer runs, and at what model and effort: the tenant's defaults, and a
 * repository's own values over them.
 */
class ReviewSettingsForm
{
    /**
     * @param  ReviewSetting|null  $defaults  the tenant's defaults, when the form is a repository's
     * @param  bool  $withProviders  with Claude's and Codex's model and effort; the tenant's are set with its AI providers
     * @return array<int, Component>
     */
    public static function fields(?ReviewSetting $defaults = null, bool $withProviders = true): array
    {
        return [
            // What runs as the form stands, before anything else: which reviewer,
            // in which order, at which model and effort.
            Text::make(fn (Get $get): HtmlString => self::summary($get, $defaults))->key('summary'),
            Repeater::make('providers')
                ->label('Reviewers, in order')
                ->live()
                ->schema([
                    Select::make('provider')
                        ->label('Reviewer')
                        ->options([
                            ReviewProvider::ClaudeCode->value => ReviewProvider::ClaudeCode->text(),
                            ReviewProvider::CodexCli->value => ReviewProvider::CodexCli->text(),
                            ReviewProvider::Ocr->value => ReviewProvider::Ocr->text(),
                        ])
                        ->required()
                        ->distinct(),
                    TextInput::make('share')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(100)
                        ->required()
                        ->dehydrateStateUsing(fn (mixed $state): int => (int) $state),
                    TextInput::make('budget')
                        ->label('Monthly budget')
                        ->numeric()
                        ->minValue(0)
                        ->prefix(fn (): string => app(Currency::class)->symbol(CurrentTenant::get()))
                        ->placeholder('No limit')
                        ->hint(fn (Get $get): ?string => filled($get('provider')) ? 'Spent: '.Currency::show(ReviewSettings::spentThisMonth(CurrentTenant::get())[$get('provider')] ?? 0) : null)
                        ->dehydrateStateUsing(fn (mixed $state): ?float => filled($state) ? (float) $state : null),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->reorderable()
                ->addActionLabel('Add reviewer')
                ->helperText('Each pull request gets one of these, picked by share among those there is a key for, and keeps it for every push. A share of 0 runs only when nothing above it can. '
                    .'One whose reviews have cost its monthly budget is passed over for the rest of the month: to use one until its budget is spent and another after, give the first a share and a budget and the second a share of 0. '
                    .($defaults === null ? 'Empty leaves it to the keys: Claude Code with an Anthropic key, else Codex.' : 'Empty uses the defaults.')),
            ...($withProviders ? [
                Fieldset::make(ReviewProvider::ClaudeCode->text())->columns(2)->schema(self::claudeFields($defaults)),
                Fieldset::make(ReviewProvider::CodexCli->text())->columns(2)->schema(self::codexFields($defaults)),
            ] : []),
            Fieldset::make(ReviewProvider::Ocr->text())
                ->columns(2)
                ->schema([
                    // No tier and no alias: OpenCodeReview picks its own provider and passes the name on as it is,
                    // so a tier could be read for the wrong one and an alias names no model at all.
                    self::model($defaults, 'ocr_model', array_diff_key(self::claudeModels() + self::openAiModels(), array_flip([...ReviewSettings::TIERS, ...self::CLAUDE_ALIASES]))),
                    self::effort($defaults, 'ocr_effort'),
                ]),
            Select::make('language')
                ->label('Language')
                ->options(ReviewSettings::LANGUAGES)
                ->placeholder(fn (): string => $defaults !== null && filled($defaults->language) ? 'Default: '.(ReviewSettings::LANGUAGES[$defaults->language] ?? $defaults->language) : 'English')
                ->helperText('What the review writes for people: findings, the summary, answers. Code, paths and the report\'s headings stay as they are.'),
            Select::make('ticket_grading')
                ->label('Grade against the ticket')
                ->options(['true' => 'On', 'false' => 'Off'])
                ->placeholder(match ($defaults?->ticket_grading) {
                    'true' => 'Default: on',
                    'false' => 'Default: off',
                    default => 'On',
                })
                ->helperText('On: the summary grades the change against the ticket\'s acceptance criteria, one by one. Off: the ticket is read for intent only, for teams whose tickets hold no criteria.'),
            Select::make('extra_passes')
                ->label('Extra passes')
                ->options(['true' => 'On', 'false' => 'Off'])
                ->placeholder(match ($defaults?->extra_passes) {
                    'true' => 'Default: on',
                    'false' => 'Default: off',
                    default => 'Off',
                })
                ->helperText('For Claude Code, where the image has them: the review-toolkit agents and /code-review read the change as well, each from scratch. Off by default: they find a little more at several times the cost of a review.'),
            Select::make('verify')
                ->label('Second check')
                ->options(['true' => 'On', 'false' => 'Off'])
                ->placeholder(match ($defaults?->verify) {
                    'true' => 'Default: on',
                    'false' => 'Default: off',
                    default => 'Off',
                })
                ->helperText('Up to five new findings that would block the merge are each checked once more, in a fresh session that did not write them. Each check adds to the review\'s time and cost.'),
            Select::make('follow_ups')
                ->label('New commit during a review')
                ->options([0 => 'Start a fresh review for it', 1 => 'Carry on with it, once', 2 => 'Carry on with it, up to twice'])
                ->placeholder(match ($defaults?->follow_ups) {
                    null => $defaults === null ? 'Start a fresh review for it' : 'Default: start a fresh review for it',
                    0 => 'Default: start a fresh review for it',
                    1 => 'Default: carry on, once',
                    default => 'Default: carry on, up to twice',
                })
                ->helperText('For reviews that run in a container on this server. Carrying on publishes the review first, then reviews the new commit in the same checkout: no new clone, and Claude resumes its session, so it starts from what it already read and found.'),
        ];
    }

    /**
     * Claude Code's model, effort, advisor and budget per review.
     *
     * @return array<int, Component>
     */
    public static function claudeFields(?ReviewSetting $defaults = null): array
    {
        return [
            self::model($defaults, 'claude_model', self::claudeModels()),
            self::effort($defaults, 'claude_effort'),
            self::model($defaults, 'claude_advisor', ['none' => 'none: no advisor', ...self::claudeModels()])
                ->label('Advisor')
                ->helperText('A stronger model Claude consults at the moments that decide a review; none for no advisor.'),
            TextInput::make('claude_max_budget')
                ->label('Budget per review ($)')
                ->maxLength(16)
                ->regex('/^(none|(?=.*[1-9])\d+(\.\d{1,2})?)$/')
                ->validationMessages(['regex' => 'A dollar amount above 0, such as 10 or 7.5, or none for no cap.'])
                ->placeholder(self::placeholder($defaults, 'claude_max_budget'))
                ->helperText('The most one Claude review may spend. It stops there without a report, and the review ends as "Did not finish". Empty uses $10; none for no cap.'),
        ];
    }

    /**
     * Codex's model and effort.
     *
     * @return array<int, Component>
     */
    public static function codexFields(?ReviewSetting $defaults = null): array
    {
        return [
            self::model($defaults, 'codex_model', self::openAiModels()),
            self::effort($defaults, 'codex_effort'),
        ];
    }

    /**
     * Which reviewer a pull request gets as the form stands, and at what: the list
     * in its order with each one's share and budget, or the one the keys decide on,
     * and the model and effort it runs at.
     */
    protected static function summary(Get $get, ?ReviewSetting $defaults): HtmlString
    {
        $tenant = CurrentTenant::get();
        $keys = app(ReviewCredentials::class)->reviewers($tenant);
        $entries = array_values(array_filter((array) ($get('providers') ?? []), fn (mixed $entry): bool => is_array($entry) && filled($entry['provider'] ?? null)));

        if ($entries === [] && $defaults !== null) {
            return new HtmlString('<p class="rc-muted">No reviewers of its own: it uses the team\'s, from Review defaults, with the model and effort set below where they are.</p>');
        }

        if ($entries === []) {
            $picked = match (true) {
                in_array(ReviewProvider::ClaudeCode->value, $keys, true) => ReviewProvider::ClaudeCode,
                in_array(ReviewProvider::CodexCli->value, $keys, true) => ReviewProvider::CodexCli,
                default => null,
            };

            return new HtmlString($picked === null
                ? '<p><strong>No reviewer can run:</strong> add an AI provider first.</p>'
                : '<p><strong>Every pull request is reviewed by '.e($picked->text()).'</strong>, '.e(self::runsAt($get, $picked, $defaults)).'. None is listed, so the keys decide: Claude Code with an Anthropic key, else Codex.</p>');
        }

        $items = collect($entries)->map(function (array $entry) use ($get, $keys, $defaults): string {
            $provider = ReviewProvider::tryFrom((string) $entry['provider']);
            $share = (int) ($entry['share'] ?? 0);
            $notes = array_filter([
                $share === 0 ? 'only when none above can run' : null,
                filled($entry['budget'] ?? null) ? 'up to '.Currency::showOwn((float) $entry['budget']).' a month' : null,
                $provider !== null && ! in_array($provider->value, $keys, true) ? 'no key for it, so passed over' : null,
            ]);

            return '<li><strong>'.e($provider?->text() ?? (string) $entry['provider']).'</strong>'
                .($share > 0 ? ' · share '.$share : '')
                .($provider === null ? '' : ', '.e(self::runsAt($get, $provider, $defaults)))
                .($notes === [] ? '' : ' <span class="rc-muted">('.e(implode('; ', $notes)).')</span>').'</li>';
        })->implode('');

        return new HtmlString('<p><strong>Each pull request is reviewed by one of these</strong>, by share, and keeps it for every push:</p><ul class="rc-summary-list">'.$items.'</ul>');
    }

    /**
     * The model and effort a reviewer runs at, in words: as set, a tier with the
     * model it is now, or what the defaults or the reviewer itself decide.
     */
    protected static function runsAt(Get $get, ReviewProvider $provider, ?ReviewSetting $defaults): string
    {
        [$model, $effort, $driver] = match ($provider) {
            ReviewProvider::ClaudeCode => ['claude_model', 'claude_effort', 'anthropic'],
            ReviewProvider::CodexCli => ['codex_model', 'codex_effort', 'openai'],
            default => ['ocr_model', 'ocr_effort', null],
        };

        $value = fn (string $field): ?string => filled($get($field)) ? (string) $get($field) : (filled($defaults?->{$field}) ? (string) ($defaults->{$field} instanceof ReviewEffort ? $defaults->{$field}->value : $defaults->{$field}) : null);
        $named = $value($model);
        $tierModel = $named !== null && $driver !== null && in_array($named, ReviewSettings::TIERS, true) ? (ReviewSettings::tierModels($driver)[$named] ?? null) : null;

        return 'model '.($named === null ? 'as the reviewer decides' : $named.($tierModel === null ? '' : " (now {$tierModel})"))
            .', effort '.(($effort = $value($effort)) === null ? 'as the reviewer decides' : (ReviewEffort::tryFrom($effort)?->text() ?? $effort));
    }

    protected static function placeholder(?ReviewSetting $defaults, string $field): string
    {
        $default = $defaults?->{$field};

        return match (true) {
            $defaults === null => 'The reviewer decides',
            $default instanceof ReviewEffort => 'Default: '.$default->text(),
            is_string($default) && $default !== '' => 'Default: '.$default,
            default => 'Default: the reviewer decides',
        };
    }

    /**
     * A model, picked from the ones known here or typed: a gateway or a new model
     * has a name no list holds yet.
     *
     * @param  array<string, string>  $choices  model => label
     */
    protected static function model(?ReviewSetting $defaults, string $field, array $choices): Select
    {
        return self::modelField($field, $choices)->placeholder(self::placeholder($defaults, $field));
    }

    /**
     * A model field of any form: picked from the choices given, or typed.
     *
     * @param  array<string, string>  $choices  model => label
     */
    public static function modelField(string $field, array $choices): Select
    {
        $search = function (string $term) use ($choices): array {
            $term = trim($term);
            $found = collect($choices)->filter(fn (string $label, string $model): bool => str_contains(mb_strtolower($model.' '.$label), mb_strtolower($term)))->take(50)->all();

            // What was typed, as a model of its own, where it is a name a model can have.
            return preg_match(self::MODEL_NAME, $term) === 1 && ! array_key_exists($term, $found)
                ? [$term => "{$term} (as typed)"] + $found
                : $found;
        };

        return Select::make($field)
            ->live()
            ->label('Model')
            ->searchable()
            ->options($choices)
            ->getSearchResultsUsing($search)
            // One saved before, or typed, that no list holds: shown as it is.
            ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : ($choices[$value] ?? $value))
            ->rule('regex:'.self::MODEL_NAME);
    }

    /** The names only Claude Code knows a model by. */
    protected const CLAUDE_ALIASES = ['opus', 'sonnet', 'haiku'];

    /** What a model's name can be: an ID, an alias, or a gateway's provider/model. */
    protected const MODEL_NAME = '/^[A-Za-z0-9._:\/@-]{1,255}$/';

    /**
     * Claude's models: the aliases Claude Code knows, the SDK's smartest, default
     * and cheapest, then the other IDs the price list has, newest first.
     *
     * @return array<string, string>
     */
    public static function claudeModels(): array
    {
        // A union, not a spread: a model the SDK names keeps its label and its place.
        return [
            'opus' => 'opus: the newest Opus',
            'sonnet' => 'sonnet: the newest Sonnet',
            'haiku' => 'haiku: the newest Haiku',
        ] + self::tiers('anthropic') + self::priced('/^claude-[a-z0-9.-]+$/');
    }

    /**
     * OpenAI's models: the SDK's smartest, default and cheapest, then the other
     * IDs the price list has, newest first.
     *
     * @return array<string, string>
     */
    public static function openAiModels(): array
    {
        return self::tiers('openai') + self::priced('/^(gpt-[0-9][a-z0-9.-]*|o[0-9][a-z0-9.-]*|codex-[a-z0-9.-]+)$/');
    }

    /**
     * The provider's smartest, default and cheapest: as a tier, which follows the
     * Laravel AI SDK when the review runs, then as the model each is now. First
     * in the list, and there before the price list has any.
     *
     * @return array<string, string>
     */
    protected static function tiers(string $driver): array
    {
        $models = ReviewSettings::tierModels($driver);
        $tiers = [];

        foreach ($models as $tier => $model) {
            $tiers[$tier] = "{$tier}: now {$model}";
        }

        foreach ($models as $tier => $model) {
            $tiers[$model] ??= "{$model} ({$tier})";
        }

        return $tiers;
    }

    /**
     * The models of the price list whose ID matches, without dated snapshots,
     * newest first.
     *
     * @return array<string, string>
     */
    protected static function priced(string $pattern): array
    {
        $models = once(fn (): array => rescue(fn (): array => ModelPrice::query()->pluck('model')->all(), [], false));
        $matching = collect($models)
            ->filter(fn (string $model): bool => preg_match($pattern, $model) === 1 && preg_match('/-\d{8}$|-\d{4}-\d{2}-\d{2}$/', $model) !== 1)
            ->sort(SORT_NATURAL)
            ->reverse()
            ->values();

        return $matching->mapWithKeys(fn (string $model): array => [$model => $model])->all();
    }

    protected static function effort(?ReviewSetting $defaults, string $field): Select
    {
        return Select::make($field)
            ->live()
            ->label('Effort')
            ->options(collect(ReviewEffort::cases())->mapWithKeys(fn (ReviewEffort $effort): array => [$effort->value => $effort->text()])->all())
            ->placeholder(self::placeholder($defaults, $field));
    }

    /**
     * A setting's values as the form takes them.
     *
     * @return array<string, mixed>
     */
    public static function state(?ReviewSetting $setting): array
    {
        return $setting === null ? [] : collect($setting->attributesToArray())
            ->only(['providers', 'claude_model', 'claude_effort', 'claude_advisor', 'claude_max_budget', 'codex_model', 'codex_effort', 'ocr_model', 'ocr_effort', 'verify', 'follow_ups', 'language', 'ticket_grading', 'extra_passes'])
            ->all();
    }
}
