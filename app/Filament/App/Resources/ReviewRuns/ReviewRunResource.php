<?php

namespace App\Filament\App\Resources\ReviewRuns;

use App\Enums\ReviewEffort;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Filament\CurrentTenant;
use App\Filament\LocalTime;
use App\Filament\RunPresenter;
use App\Models\ReviewRun;
use App\Runners\DiffRunner;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\ReviewCredentials;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
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
    public static function trialFields(): array
    {
        return [
            Toggle::make('ignore_existing')
                ->label('Review as though nobody had')
                ->default(true)
                ->helperText('Leaves out the comments and earlier findings on the pull request, from this review and from people. Turn it off to try a follow-up review instead.'),
            Select::make('runner')
                ->label('Runs on')
                ->options(fn (Runners $runners): array => $runners->options())
                ->placeholder('As the repository would')
                ->live()
                ->helperText('Docker and a Cloudflare sandbox run a reviewer that reads the repository. The diff alone sends only the pull request\'s diff to the AI provider: no clone and no reviewer to choose; the model and effort below then name the provider\'s own.'),
            Select::make('provider')
                ->label('Reviewer')
                ->options(fn (ReviewCredentials $credentials): array => $credentials->trialReviewers(CurrentTenant::get()))
                ->placeholder('As the repository would get')
                ->visible(fn (Get $get): bool => $get('runner') !== DiffRunner::NAME),
            TextInput::make('model')
                ->maxLength(100)
                ->regex('/^[\\w.:\\/-]+$/')
                ->placeholder('The reviewer\'s own')
                ->helperText('As the reviewer names it, such as claude-opus-5-5 or sonnet. For Pi, a provider can go in front: google/gemini-3-pro.'),
            Select::make('effort')
                ->options(collect(ReviewEffort::cases())->mapWithKeys(fn (ReviewEffort $effort): array => [$effort->value => $effort->text()]))
                ->placeholder('The reviewer\'s own'),
            Toggle::make('verify')
                ->label('Check each blocking finding a second time'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  what the trial fields gave
     * @return array{runner: string|null, provider: string|null, model: string|null, effort: string|null, verify: bool, ignore_existing: bool}
     */
    public static function trialOptions(array $data): array
    {
        return [
            'runner' => filled($data['runner'] ?? null) ? (string) $data['runner'] : null,
            'provider' => filled($data['provider'] ?? null) ? (string) $data['provider'] : null,
            'model' => filled($data['model'] ?? null) ? (string) $data['model'] : null,
            'effort' => filled($data['effort'] ?? null) ? (string) $data['effort'] : null,
            'verify' => (bool) ($data['verify'] ?? false),
            'ignore_existing' => (bool) ($data['ignore_existing'] ?? true),
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
