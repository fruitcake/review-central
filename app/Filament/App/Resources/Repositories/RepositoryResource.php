<?php

namespace App\Filament\App\Resources\Repositories;

use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Enums\ReviewProvider;
use App\Filament\App\Resources\Repositories\Pages\ManageRepositories;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\RepositoryReadiness;
use App\Services\ReviewSettings;
use App\Services\ReviewStack;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rules\Unique;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static ?string $recordTitleAttribute = 'slug';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('connection_id')
                    ->label('Connection')
                    ->options(fn (): array => self::connections()->pluck('name', 'id')->all())
                    // One connection is the usual case, and then there is nothing to choose.
                    ->default(fn (): ?int => self::connections()->count() === 1 ? self::connections()->value('id') : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('slug', null))
                    // The workspace comes with the connection, and a repository does not move to another.
                    ->disabledOn('edit')
                    ->helperText('The source control connection the review reads and comments with. Add one under Source control first.'),
                Select::make('slug')
                    ->label('Repository')
                    ->required()
                    ->searchable()
                    ->searchPrompt('Type a part of the repository\'s name.')
                    ->getSearchResultsUsing(function (string $search, Get $get): array {
                        $connection = self::connections()->whereKey($get('connection_id'))->first();
                        $driver = $connection?->driver();

                        return $connection !== null && $driver instanceof SourceControlDriver
                            ? rescue(fn (): array => $driver->searchRepositories($connection, $search), [])
                            : [];
                    })
                    // The name in the address is what is stored, and it is its own label.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) && preg_match('/^[\w.-]{1,255}$/', $value) === 1 ? $value : null)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('connection_id', $get('connection_id')))
                    ->disabled(fn (Get $get): bool => blank($get('connection_id')))
                    ->helperText('Searched in the connection\'s workspace. Its stack is detected when it is added.'),
                TextInput::make('name')->maxLength(255)->helperText('Optional, for the lists here.')->visibleOn('edit'),
                Select::make('review_runner')
                    ->label('Runs on')
                    ->options(fn (): array => app(Runners::class)->options())
                    // Nothing chosen follows the platform, also when it changes.
                    ->placeholder(fn (): string => 'As the platform: '.rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->default())->label(), '', false))
                    ->live()
                    ->helperText(fn (Get $get): ?string => rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->nameFor($get('review_runner') ?: null))->description(), null, false)),
                Toggle::make('reviews_enabled')
                    ->label('Review its pull requests')
                    ->default(true)
                    ->visibleOn('edit')
                    ->helperText('Off, nothing starts a review of this repository but a person: a repository added by trying a pull request starts that way.'),
                CheckboxList::make('review_stack')
                    ->label('Stack')
                    ->options(fn (): array => array_combine($stacks = ReviewStack::known(CurrentTenant::get()), array_map('ucfirst', $stacks)))
                    ->columns(4)
                    ->visibleOn('edit')
                    ->helperText('What the repository is built on, which decides the stack guides the review reads. Detected from its composer.json when it was added.'),
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
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('integration')
                ->withMax('reviewRuns', 'started_at')
                ->withSum(['reviewRuns as cost_this_month' => fn (Builder $runs) => $runs->where('started_at', '>=', now()->startOfMonth())], 'cost'))
            ->columns([
                TextColumn::make('slug')
                    ->label('Repository')
                    ->formatStateUsing(fn (Repository $record): string => $record->fullName())
                    ->description(fn (Repository $record): ?string => $record->reviews_enabled ? $record->name : 'Trials only: its pull requests are not reviewed')
                    ->searchable(['slug', 'workspace', 'name']),
                TextColumn::make('integration.name')->label('Connection'),
                // Also for a repository that follows the platform, which stores nothing.
                TextColumn::make('review_runner')->label('Runs on')
                    ->state(fn (Repository $record): string => $record->runner()->label().($record->review_runner === null ? ' (platform)' : '')),
                TextColumn::make('review_available_providers')->label('Reviewers available')->badge()
                    ->formatStateUsing(fn (string $state): string => ReviewProvider::tryFrom($state)?->text() ?? $state)
                    ->placeholder('Known after the first review'),
                TextColumn::make('review_runs_max_started_at')->label('Last review')->since()->placeholder('Never'),
                TextColumn::make('cost_this_month')->label('Cost this month')->state(fn (Repository $record): string => Currency::show($record->getAttribute('cost_this_month'))),
            ])
            ->recordActions([
                Action::make('host')
                    ->label('Open on its host')
                    ->tooltip(fn (Repository $record): string => 'Open on '.rescue(fn (): string => $record->integration->driver()->label(), 'its host', false))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (Repository $record): ?string => rescue(fn (): string => $record->sourceControl()->repositoryUrl(), null, false), shouldOpenInNewTab: true),
                Action::make('readiness')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->modalHeading(fn (Repository $record): string => "Can {$record->slug} be reviewed?")
                    ->modalDescription('Checked now, against the host and this installation.')
                    ->modalContent(fn (Repository $record, RepositoryReadiness $readiness) => view('filament.readiness', ['checks' => $readiness->checks($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                ActionGroup::make([
                    Action::make('reviewSettings')
                        ->label('Review settings')
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->modalDescription('Left empty, a setting follows the defaults.')
                        ->fillForm(fn (Repository $record): array => ReviewSettingsForm::state($record->reviewSetting))
                        ->schema(fn (): array => ReviewSettingsForm::fields(ReviewSettings::defaults(CurrentTenant::get())))
                        ->action(function (Repository $record, array $data): void {
                            ReviewSettings::saveFor($record, $data);

                            Notification::make()->title('Saved')->success()->send();
                        }),
                    EditAction::make(),
                    DeleteAction::make()->modalDescription('Deletes the repository with its review history.'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRepositories::route('/'),
        ];
    }
}
