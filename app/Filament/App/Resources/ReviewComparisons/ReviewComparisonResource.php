<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewComparisons;

use App\Filament\App\Resources\ReviewComparisons\Pages\ListReviewComparisons;
use App\Filament\App\Resources\ReviewComparisons\Pages\ViewReviewComparison;
use App\Filament\Concerns\ForTenantAdmins;
use App\Filament\LocalTime;
use App\Models\ReviewComparison;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reviews of one pull request with different setups, side by side. The tenant's
 * admins start them, from a review, and pay for every trial in them.
 */
class ReviewComparisonResource extends Resource
{
    use ForTenantAdmins;

    protected static ?string $model = ReviewComparison::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Comparisons';

    protected static ?string $modelLabel = 'comparison';

    protected static ?int $navigationSort = 11;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['repository', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('pull_request_id')
                    ->label('Pull request')
                    ->state(fn (ReviewComparison $record): string => "{$record->repository->slug} #{$record->pull_request_id}"),
                TextColumn::make('rows')
                    ->label('Setups')
                    ->state(fn (ReviewComparison $record): int => count($record->rows) + 1),
                TextColumn::make('analysis_status')
                    ->label('Analysis')
                    ->badge()
                    ->placeholder('Not yet'),
                TextColumn::make('user.name')->label('By')->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->formatStateUsing(fn (ReviewComparison $record): string => LocalTime::of($record->created_at)->format('j M Y, H:i')),
            ])
            ->recordActions([ViewAction::make()->label('Open')->icon(null)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewComparisons::route('/'),
            'view' => ViewReviewComparison::route('/{record}'),
        ];
    }
}
