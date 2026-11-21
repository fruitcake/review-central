<?php

namespace App\Filament\App\Resources\ReviewRuns;

use App\Enums\ReviewEffort;
use App\Enums\ReviewProvider;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Filament\LocalTime;
use App\Filament\RunPresenter;
use App\Models\ReviewRun;
use App\Runners\DiffRunner;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\ReviewCredentials;
use App\Services\ReviewSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Every review run of the tenant: what it found, how long it took and what it cost.
 * Read-only; the reviews write them.
 */
class ReviewRunResource extends Resource
{
    protected static ?string $model = ReviewRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review';

    protected static ?int $navigationSort = 10;

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ReviewRun::STATUS_FINISHED => 'success',
            ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_PENDING => 'info',
            ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('repository.integration'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('slug')
                    ->label('Pull request')
                    ->state(fn (ReviewRun $record): string => RunPresenter::title($record))
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (ReviewRun $record): string => collect([
                        $record->slug.($record->pull_request_id ? " #{$record->pull_request_id}" : ''),
                        $record->branch,
                        LocalTime::of($record->started_at)->isSameDay(LocalTime::now()) ? $record->started_at->diffForHumans() : LocalTime::of($record->started_at)->format('j M H:i'),
                    ])->implode(' · '))
                    ->searchable(['slug', 'branch'])
                    ->sortable(['started_at']),
                TextColumn::make('status')
                    ->label('Result')
                    ->html()
                    ->state(fn (ReviewRun $record): HtmlString => self::chip(RunPresenter::outcome($record)['label'], RunPresenter::outcome($record)['tone'])),
                TextColumn::make('findings')
                    ->html()
                    ->state(function (ReviewRun $record): HtmlString {
                        if ($record->findings === null) {
                            return self::chip('…', 'neutral');
                        }

                        $open = RunPresenter::openBySeverity($record);

                        return $open === []
                            ? self::chip('None', 'none')
                            : new HtmlString(collect($open)->map(fn (int $count, string $severity): string => (string) self::chip("{$count} {$severity}", $severity))->implode(' '));
                    }),
                TextColumn::make('runner')
                    ->label('Runner · reviewer')
                    ->state(fn (ReviewRun $record): string => rescue(fn (): string => app(Runners::class)->get((string) $record->runner)->shortLabel(), 'Unknown', false))
                    // Who reviewed and with which model: two reviewers can share one.
                    ->description(fn (ReviewRun $record): ?string => implode(' · ', array_filter([RunPresenter::reviewer($record), $record->model])) ?: null),
                TextColumn::make('duration_seconds')->label('Duration')
                    ->formatStateUsing(fn (?int $state): ?string => RunPresenter::duration($state))->placeholder('—'),
                TextColumn::make('cost')->formatStateUsing(fn (mixed $state): string => Currency::show($state))->placeholder('—')->sortable()
                    ->description(fn (ReviewRun $record): ?string => $record->cost_estimated ? 'estimated' : null),
            ])
            ->filters([
                SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => CurrentTenant::get()->repositories()->pluck('slug', 'id')->all()),
                SelectFilter::make('runner')
                    ->options(fn (): array => array_map(
                        fn (string $name): string => app(Runners::class)->get($name)->shortLabel(),
                        array_combine($names = array_map(strval(...), array_keys((array) config('review.runners'))), $names),
                    )),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->deferFilters(false)
            ->recordUrl(fn (ReviewRun $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('pullRequest')
                    ->label('Open the pull request')
                    ->tooltip('Open the pull request on its host')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (ReviewRun $record): bool => $record->pull_request_id !== null)
                    ->url(fn (ReviewRun $record): ?string => rescue(fn (): ?string => $record->pullRequestUrl(), null, false), shouldOpenInNewTab: true),
                self::dismissAction(),
                ViewAction::make()->label('Open')->icon(null),
            ])
            ->toolbarActions([
                BulkAction::make('dismiss')
                    ->label('Set aside')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->requiresConfirmation()
                    ->modalDescription('The reviews among these that did not finish leave "Did not finish" and "Needs action". They stay under All and in the figures.')
                    ->action(fn (Collection $records) => $records->each(fn (Model $run) => $run instanceof ReviewRun && $run->canBeDismissed() && $run->update(['dismissed_at' => now()])))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * How a trial runs: the runner, the reviewer, its model and effort, and what
     * it reads of the pull request. For "Try without posting" and a re-run.
     *
     * @return array<int, Component>
     */
    public static function trialFields(bool $withExisting = true): array
    {
        return [
            ...($withExisting ? [self::ignoreExistingField()] : []),
            Select::make('runner')
                ->label('Runs on')
                ->options(fn (Runners $runners): array => $runners->options())
                ->placeholder('As the repository would')
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::keepModel($get, $set))
                ->helperText('Docker and a Cloudflare sandbox run a reviewer that reads the repository. The diff alone sends only the pull request\'s diff to the AI provider: no clone and no reviewer to choose; the model and effort below then name the provider\'s own.'),
            Select::make('provider')
                ->label('Reviewer')
                ->options(fn (ReviewCredentials $credentials): array => $credentials->trialReviewers(CurrentTenant::get()))
                ->placeholder('As the repository would get')
                // The models to pick from follow the reviewer.
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::keepModel($get, $set))
                ->visible(fn (Get $get): bool => $get('runner') !== DiffRunner::NAME),
            ReviewSettingsForm::modelField('model', fn (Get $get): array => self::trialModels($get('runner') === DiffRunner::NAME ? null : $get('provider')))
                ->placeholder('The reviewer\'s own')
                ->helperText('Pick one, or type the name as the reviewer takes it. A Laravel AI tier is read for Claude Code or Codex when the trial starts. For Pi, a provider can go in front: google/gemini-3-pro.'),
            Select::make('effort')
                ->options(collect(ReviewEffort::cases())->mapWithKeys(fn (ReviewEffort $effort): array => [$effort->value => $effort->text()]))
                ->placeholder('The reviewer\'s own'),
            Toggle::make('plain')
                ->label('Plain review: the reviewer alone')
                ->live()
                ->visible(fn (Get $get): bool => $get('runner') !== DiffRunner::NAME)
                ->helperText('Claude Code or Codex asked for a review with the review\'s own instructions, and nothing else: no OpenCodeReview, no scan, no floor guard, no extra passes and no second check. To see what those add.'),
            Fieldset::make('Parts of the review')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('runner') !== DiffRunner::NAME && ! $get('plain'))
                ->schema(array_map(
                    fn (string $field): Select => Select::make("modules.{$field}")
                        ->label(ucfirst(ReviewSettings::MODULE_LABELS[$field]))
                        ->options(['true' => 'On', 'false' => 'Off'])
                        ->placeholder('As the repository has it'),
                    array_keys(ReviewSettings::MODULES),
                )),
        ];
    }

    public static function ignoreExistingField(): Toggle
    {
        return Toggle::make('ignore_existing')
            ->label('Review as though nobody had')
            ->default(true)
            ->helperText('Leaves out the comments and earlier findings on the pull request, from this review and from people. Turn it off to try a follow-up review instead.');
    }

    /**
     * Clears a tier or an alias when the reviewer or runner changes: what a tier
     * means hangs on the provider that reads it, and an alias is Claude Code's. A
     * model by name stays.
     */
    protected static function keepModel(Get $get, Set $set): void
    {
        if (in_array($get('model'), [...ReviewSettings::TIERS, ...ReviewSettingsForm::CLAUDE_ALIASES], true)) {
            $set('model', null);
        }
    }

    /**
     * The models to pick from for a reviewer: Claude Code's aliases and Anthropic's
     * models, Codex's OpenAI models, both with the Laravel AI tiers; for another
     * reviewer, or none picked, the models by name only, a tier being read for one
     * provider.
     *
     * @return array<string, string>
     */
    public static function trialModels(?string $provider): array
    {
        return match ($provider) {
            ReviewProvider::ClaudeCode->value => ReviewSettingsForm::claudeModels(),
            ReviewProvider::CodexCli->value => ReviewSettingsForm::openAiModels(),
            default => array_diff_key(ReviewSettingsForm::claudeModels() + ReviewSettingsForm::openAiModels(), array_flip([...ReviewSettings::TIERS, ...ReviewSettingsForm::CLAUDE_ALIASES])),
        };
    }

    /**
     * @param  array<string, mixed>  $data  what the trial fields gave
     * @return array{runner: string|null, provider: string|null, model: string|null, effort: string|null, verify: bool|null, ignore_existing: bool, modules: array<string, bool>|null}
     */
    public static function trialOptions(array $data): array
    {
        $plain = (bool) ($data['plain'] ?? false);

        // Only what was set: a part left alone runs as the repository has it.
        $modules = $plain
            ? array_fill_keys(array_keys(ReviewSettings::MODULES), false)
            : array_map(
                fn (mixed $value): bool => $value === 'true',
                array_filter(
                    array_intersect_key((array) ($data['modules'] ?? []), ReviewSettings::MODULES),
                    fn (mixed $value): bool => in_array($value, ['true', 'false'], true),
                ),
            );

        return [
            'runner' => filled($data['runner'] ?? null) ? (string) $data['runner'] : null,
            'provider' => filled($data['provider'] ?? null) ? (string) $data['provider'] : null,
            'model' => match (true) {
                ! filled($data['model'] ?? null) => null,
                // A tier is a name only the review server knows: the reviewer gets the model it is now.
                in_array($data['model'], ReviewSettings::TIERS, true) && in_array($data['provider'] ?? null, [ReviewProvider::ClaudeCode->value, ReviewProvider::CodexCli->value], true) => ReviewSettings::model($data['provider'] === ReviewProvider::CodexCli->value ? 'openai' : 'anthropic', (string) $data['model']),
                default => (string) $data['model'],
            },
            'effort' => filled($data['effort'] ?? null) ? (string) $data['effort'] : null,
            // The second check is one of the parts, and kept apart for the run's scripts.
            'verify' => $modules['verify'] ?? null,
            'ignore_existing' => (bool) ($data['ignore_existing'] ?? true),
            'modules' => $modules === [] ? null : $modules,
        ];
    }

    /**
     * Sets aside a review that did not finish, once someone has looked at it.
     */
    public static function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label('Set aside')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->visible(fn (ReviewRun $record): bool => $record->canBeDismissed())
            ->action(fn (ReviewRun $record) => $record->update(['dismissed_at' => now()]));
    }

    /**
     * A small rounded label in one of the theme's tones.
     */
    public static function chip(string $label, string $tone): HtmlString
    {
        return new HtmlString('<span class="rc-chip rc-chip-'.e($tone).'">'.e($label).'</span>');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewRuns::route('/'),
            'view' => ViewReviewRun::route('/{record}'),
        ];
    }
}
