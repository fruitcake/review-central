<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\OpenFindings;

use App\Filament\App\Resources\OpenFindings\Pages\ListOpenFindings;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\CurrentTenant;
use App\Filament\LocalTime;
use App\Filament\TenantRole;
use App\Models\OpenFinding;
use App\Services\OpenFindings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The findings pull requests were merged with, per repository: what is still
 * there on the main branch, what was resolved since, and what someone closed.
 */
class OpenFindingResource extends Resource
{
    protected static ?string $model = OpenFinding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 13;

    public static function getNavigationLabel(): string
    {
        return __('Open findings');
    }

    public static function getModelLabel(): string
    {
        return __('open finding');
    }

    public static function getPluralModelLabel(): string
    {
        return __('open findings');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = rescue(fn (): int => OpenFinding::query()->where('tenant_id', CurrentTenant::get()->getKey())->active()->count(), 0, false);

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['repository', 'reviewRun']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('repository.slug')
                    ->label(__('Pull request'))
                    ->state(fn (OpenFinding $record): string => "{$record->repository->slug} #{$record->pull_request_id}")
                    ->url(fn (OpenFinding $record): ?string => $record->reviewRun === null ? null : ReviewRunResource::getUrl('view', ['record' => $record->reviewRun])),
                TextColumn::make('severity')
                    ->label(__('Severity'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'CRITICAL' => __('CRITICAL'),
                        'HIGH' => __('HIGH'),
                        'MEDIUM' => __('MEDIUM'),
                        'LOW' => __('LOW'),
                        default => $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'CRITICAL', 'HIGH' => 'danger',
                        'MEDIUM' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->description(fn (OpenFinding $record): ?string => $record->path === null ? null : $record->path.($record->line === null ? '' : ":{$record->line}"))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OpenFinding::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        OpenFinding::PRESENT => 'danger',
                        OpenFinding::RESOLVED => 'success',
                        OpenFinding::UNCLEAR => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (OpenFinding $record): ?string => $record->note),
                TextColumn::make('checked_at')
                    ->label(__('Checked'))
                    ->formatStateUsing(fn (OpenFinding $record): string => $record->checked_at === null ? __('Never') : LocalTime::of($record->checked_at)->translatedFormat('j M Y'))
                    ->placeholder(__('Never')),
                TextColumn::make('created_at')
                    ->label(__('Merged'))
                    ->formatStateUsing(fn (OpenFinding $record): string => LocalTime::of($record->created_at)->translatedFormat('j M Y'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(['active' => __('On the backlog')] + OpenFinding::statusLabels())
                    ->default('active')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'active' => $query->whereIn('status', OpenFinding::ACTIVE),
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('repository')->label(__('Repository'))->relationship('repository', 'slug'),
            ])
            ->headerActions([
                Action::make('checkAll')
                    ->label(__('Check all now'))
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->visible(fn (): bool => TenantRole::isAdmin())
                    ->requiresConfirmation()
                    ->modalDescription(__('A small model reads the file of every finding on the backlog, on the main branch: one request per file, with the team\'s first AI provider.'))
                    ->action(function (): void {
                        self::check(OpenFinding::query()->where('tenant_id', CurrentTenant::get()->getKey())->active()->get());
                    }),
            ])
            ->recordActions([
                Action::make('check')
                    ->label(__('Check now'))
                    ->visible(fn (OpenFinding $record): bool => TenantRole::isAdmin() && in_array($record->status, OpenFinding::ACTIVE, true) && $record->path !== null)
                    ->action(fn (OpenFinding $record) => self::check(new Collection([$record]))),
                Action::make('close')
                    ->label(__('Close'))
                    ->visible(fn (OpenFinding $record): bool => TenantRole::isAdmin() && in_array($record->status, OpenFinding::ACTIVE, true))
                    ->schema([TextInput::make('reason')->label(__('Why'))->placeholder(__('Fixed elsewhere, accepted, not a problem…'))->maxLength(500)])
                    ->action(fn (OpenFinding $record, array $data) => self::close(new Collection([$record]), $data['reason'] ?? null)),
                Action::make('reopen')
                    ->label(__('Reopen'))
                    ->visible(fn (OpenFinding $record): bool => TenantRole::isAdmin() && ! in_array($record->status, OpenFinding::ACTIVE, true))
                    ->action(fn (OpenFinding $record) => $record->update(['status' => OpenFinding::OPEN, 'note' => null, 'closed_at' => null, 'closed_by' => null])),
            ])
            ->toolbarActions([
                BulkAction::make('checkSelected')
                    ->label(__('Check now'))
                    ->visible(fn (): bool => TenantRole::isAdmin())
                    ->action(fn (Collection $records) => self::check($records)),
                BulkAction::make('closeSelected')
                    ->label(__('Close'))
                    ->visible(fn (): bool => TenantRole::isAdmin())
                    ->schema([TextInput::make('reason')->label(__('Why'))->maxLength(500)])
                    ->action(fn (Collection $records, array $data) => self::close($records, $data['reason'] ?? null)),
            ]);
    }

    /**
     * Queues a check of the findings that can be checked, per repository.
     *
     * @param  Collection<int, OpenFinding>  $findings
     */
    protected static function check(Collection $findings): void
    {
        $queued = app(OpenFindings::class)->queueCheck($findings);

        Notification::make()->title($queued === 0 ? __('Nothing to check') : __('The check is started'))->body($queued === 0 ? __('Only a finding on the backlog with a file can be checked.') : __('This list shows what it finds when it is done.'))->success()->send();
    }

    /**
     * @param  Collection<int, OpenFinding>  $findings
     */
    protected static function close(Collection $findings, ?string $reason): void
    {
        app(OpenFindings::class)->close($findings, $reason, auth()->user()?->name);
    }

    public static function getPages(): array
    {
        return ['index' => ListOpenFindings::route('/')];
    }
}
