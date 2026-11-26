<?php

namespace App\Filament\App\Resources\Repositories;

use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Enums\ReviewsFrom;
use App\Filament\App\Resources\PullRequests\PullRequestResource;
use App\Filament\App\Resources\Repositories\Pages\ManageRepositories;
use App\Filament\App\Resources\Repositories\Pages\ViewRepository;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\PullRequestSync;
use App\Services\RepositoryReadiness;
use App\Services\RepositorySetup;
use App\Services\ReviewSettings;
use App\Services\ReviewStack;
use App\Services\TenantOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rules\Unique;
use Throwable;

class RepositoryResource extends Resource
{
    /** Members see the repositories and whether they are ready; admins add, change and remove them. */
    public static function canCreate(): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    /**
     * Filament asks a delete action this, not canDelete(): without a policy it
     * would let a member remove a repository.
     */
    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return TenantRole::isAdmin() ? Response::allow() : Response::deny();
    }

    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static ?string $recordTitleAttribute = 'slug';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('repository');
    }

    public static function getPluralModelLabel(): string
    {
        return __('repositories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('connection_id')
                    ->label(__('Connection'))
                    // Editing, only those that reach the same host, GitHub with a token and as
                    // the app, and the repository's owner there.
                    ->options(fn (?Repository $record): array => self::connections()->get()
                        ->filter(fn (Connection $connection): bool => $record === null
                            || ($connection->sameHostAs($record->integration) && $connection->reachesOwner($record->workspace)))
                        ->pluck('name', 'id')->all())
                    // One connection is the usual case, and then there is nothing to choose.
                    ->default(fn (): ?int => self::connections()->count() === 1 ? self::connections()->value('id') : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, string $operation) => $operation === 'create' ? $set('slug', null) : null)
                    ->helperText(fn (string $operation): string => $operation === 'edit'
                        ? __('The connection the review reads and comments with. It can move to another one to the same host, such as from GitHub with a token to the GitHub App, and keeps its history.')
                        : __('The source control connection the review reads and comments with. Add one under Source control first.')),
                Select::make('slug')
                    ->label(__('Repository'))
                    ->required()
                    ->searchable()
                    ->searchPrompt(__('Type a part of the repository\'s name.'))
                    ->getSearchResultsUsing(function (string $search, Get $get): array {
                        $connection = self::connections()->whereKey($get('connection_id'))->first();
                        $driver = $connection?->driver();

                        return $connection !== null && $driver instanceof SourceControlDriver
                            ? rescue(fn (): array => $driver->searchRepositories($connection, $search), [])
                            : [];
                    })
                    // The name in the address is what is stored, and it is its own label.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) && preg_match('/^[\w.-]{1,255}$/', $value) === 1 ? $value : null)
                    // A removed one is not in the way: adding it again brings it back.
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('connection_id', $get('connection_id'))->whereNull('deleted_at'))
                    ->disabled(fn (Get $get): bool => blank($get('connection_id')))
                    ->helperText(__('Searched in the connection\'s workspace. Its stack is detected when it is added.')),
                TextInput::make('name')->label(__('Name'))->maxLength(255)->helperText(__('Optional, for the lists here.'))->visibleOn('edit'),
                Select::make('review_runner')
                    ->label(__('Runs on'))
                    ->options(fn (): array => app(Runners::class)->options())
                    // Nothing chosen follows the platform, also when it changes.
                    ->placeholder(fn (): string => __('As the platform: :runner', ['runner' => rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->default())->label(), '', false)]))
                    ->live()
                    ->helperText(fn (Get $get): ?string => rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->nameFor($get('review_runner') ?: null))->description(), null, false)),
                Toggle::make('reviews_enabled')
                    ->label(__('Review its pull requests'))
                    ->default(true)
                    ->live()
                    ->helperText(__('Off, nothing starts a review of this repository but a person, from Pull requests: a repository added by trying a pull request starts that way.')),
                Select::make('reviews_from')
                    ->label(__('Whose pull requests are reviewed by themselves'))
                    ->options(ReviewsFrom::options())
                    ->default(ReviewsFrom::Everyone->value)
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' && (bool) $get('reviews_enabled'))
                    ->helperText(__('The trusted accounts are set under Review settings. The others\' pull requests wait under Pull requests, and show on the pull request as waiting, until someone here starts the review. For a public repository.')),
                CheckboxList::make('review_stack')
                    ->label(__('Stack'))
                    ->options(fn (): array => array_combine($stacks = ReviewStack::known(CurrentTenant::get()), array_map('ucfirst', $stacks)))
                    ->columns(4)
                    ->visibleOn('edit')
                    ->helperText(__('What the repository is built on, which decides the stack guides the review reads. Detected from its composer.json when it was added.')),
            ])
            ->columns(1);
    }

    /**
     * @return HasMany<Connection, Tenant>
     */
    protected static function connections(): HasMany
    {
        return CurrentTenant::get()->connections()->where('kind', ConnectionKind::SourceControl);
    }

    public static function table(Table $table): Table
    {
        $isAdmin = TenantRole::isAdmin();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('integration')
                ->withMax('reviewRuns', 'started_at')
                ->withCount(['reviewRuns as reviews_last_30_days' => fn (Builder $runs) => $runs->whereIn('status', TenantOverview::REVIEWS)->where('started_at', '>=', now()->subDays(30))])
                ->withCount('openPullRequests')
                ->withSum(['reviewRuns as cost_this_month' => fn (Builder $runs) => $runs->where('started_at', '>=', now()->startOfMonth())], 'cost'))
            ->columns([
                TextColumn::make('slug')
                    ->label(__('Repository'))
                    ->formatStateUsing(fn (Repository $record): string => $record->fullName())
                    ->description(function (Repository $record): ?string {
                        if (! $record->reviews_enabled) {
                            return __('Not reviewed by itself: started from Pull requests');
                        }

                        return $record->reviews_from === ReviewsFrom::Trusted ? trim($record->name.' · '.__('trusted accounts only'), ' ·') : $record->name;
                    })
                    ->searchable(['slug', 'workspace', 'name']),
                TextColumn::make('integration.name')->label(__('Connection')),
                ToggleColumn::make('reviews_enabled')
                    ->label(__('Reviewed'))
                    // Members see it; only an admin may change it, as in the edit form.
                    // Asked once when the table is built for a request, not per row; an
                    // update builds it again and asks again.
                    ->disabled(! $isAdmin)
                    ->tooltip(__('Review its pull requests by themselves. Off, a person starts each review from Pull requests.')),
                TextColumn::make('reviews_last_30_days')->label(__('Reviews (30 days)'))->numeric(),
                TextColumn::make('open_pull_requests_count')->label(__('Open PRs'))
                    ->formatStateUsing(fn (int $state): string => (string) $state)
                    ->url(fn (Repository $record): string => self::pullRequestsUrl($record)),
                TextColumn::make('review_runs_max_started_at')->label(__('Last review'))->since()->placeholder(__('Never')),
                TextColumn::make('cost_this_month')->label(__('Cost this month'))->state(fn (Repository $record): string => Currency::show($record->getAttribute('cost_this_month'))),
            ])
            ->recordUrl(fn (Repository $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                self::hostAction()->iconButton(),
                self::readinessAction(),
                ActionGroup::make([
                    self::fetchPullRequestsAction(),
                    self::reviewSettingsAction(),
                    EditAction::make()
                        // Moved where a removed copy of it is, the two change places.
                        ->using(fn (Repository $record, array $data, RepositorySetup $setup): Repository => $setup->update($record, $data)),
                    DeleteAction::make()
                        ->label(__('Remove'))
                        ->modalHeading(__('Remove the repository?'))
                        ->modalDescription(__('Nothing is reviewed on it any more, and it leaves this list. Its reviews, questions and pull requests are kept, and adding it again brings it back with them.')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRepositories::route('/'),
            'view' => ViewRepository::route('/{record}'),
        ];
    }

    /**
     * The repository on its host, in a new tab.
     */
    public static function hostAction(): Action
    {
        return Action::make('host')
            ->label(__('Open on its host'))
            ->tooltip(fn (Repository $record): string => rescue(fn (): string => __('Open on :host', ['host' => $record->integration->driver()->label()]), __('Open on its host'), false))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Repository $record): ?string => rescue(fn (): string => $record->sourceControl()->repositoryUrl(), null, false), shouldOpenInNewTab: true);
    }

    /**
     * The address of the repository's open pull requests in the list of them.
     */
    public static function pullRequestsUrl(Repository $repository): string
    {
        return PullRequestResource::getUrl('index', ['filters' => ['repository_id' => ['value' => $repository->getKey()]]]);
    }

    /**
     * Reads the repository's open pull requests from its host into the list, as
     * their deliveries would have: starting no review. For one added after they
     * were opened, or whose deliveries were missed.
     */
    public static function fetchPullRequestsAction(): Action
    {
        return Action::make('fetchPullRequests')
            ->label(__('Fetch pull requests'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            // A plain git server has none.
            ->visible(fn (Repository $record): bool => $record->integration?->driver !== 'git')
            ->action(function (Repository $record, PullRequestSync $sync): void {
                try {
                    $found = $sync->repository($record);
                } catch (Throwable $exception) {
                    Notification::make()->title(__('The pull requests could not be read'))->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($found['listed'] === 1 ? __(':count open pull request', ['count' => $found['listed']]) : __(':count open pull requests', ['count' => $found['listed']]))
                    ->body($found['closed'] > 0 ? __(':count no longer open left the list. No review was started.', ['count' => $found['closed']]) : __('No review was started.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Whether the repository can be reviewed, checked now.
     */
    public static function readinessAction(): Action
    {
        return Action::make('readiness')
            ->label(__('Readiness'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->modalHeading(fn (Repository $record): string => __('Can :repository be reviewed?', ['repository' => $record->slug]))
            ->modalDescription(__('Checked now, against the host and this installation.'))
            ->modalContent(fn (Repository $record, RepositoryReadiness $readiness) => view('filament.readiness', ['checks' => $readiness->checks($record)]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /**
     * The repository's own review settings, over the team's defaults.
     */
    public static function reviewSettingsAction(): Action
    {
        return Action::make('reviewSettings')
            ->label(__('Review settings'))
            ->visible(fn (): bool => TenantRole::isAdmin())
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalDescription(__('Left empty, a setting follows the defaults.'))
            ->fillForm(fn (Repository $record): array => ReviewSettingsForm::state($record->reviewSetting))
            ->schema(fn (): array => ReviewSettingsForm::fields(ReviewSettings::defaults(CurrentTenant::get())))
            ->action(function (Repository $record, array $data): void {
                ReviewSettings::saveFor($record, $data);

                Notification::make()->title(__('Saved'))->success()->send();
            });
    }
}
