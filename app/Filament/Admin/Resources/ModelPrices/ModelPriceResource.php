<?php

namespace App\Filament\Admin\Resources\ModelPrices;

use App\Filament\Admin\Resources\ModelPrices\Pages\ManageModelPrices;
use App\Models\ModelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The prices a run's tokens are costed with when its reviewer reports no cost.
 * Read-only: the daily sync writes them.
 */
class ModelPriceResource extends Resource
{
    protected static ?string $model = ModelPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    public static function table(Table $table): Table
    {
        $perMillion = fn (?string $state): ?string => $state === null ? null : '$'.number_format((float) $state * 1_000_000, 2);

        return $table
            ->defaultSort('model')
            ->columns([
                TextColumn::make('model')->searchable()->sortable(),
                TextColumn::make('input')->label('Input / 1M')->formatStateUsing($perMillion),
                TextColumn::make('output')->label('Output / 1M')->formatStateUsing($perMillion),
                TextColumn::make('cache_read')->label('Cache read / 1M')->formatStateUsing($perMillion),
                TextColumn::make('cache_creation')->label('Cache write / 1M')->formatStateUsing($perMillion),
                TextColumn::make('fetched_at')->since()->sortable(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModelPrices::route('/'),
        ];
    }
}
