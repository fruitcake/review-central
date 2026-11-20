<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\IntegrationApps;

use App\Connections\Integrations;
use App\Filament\App\Resources\IntegrationApps\Pages\ManageIntegrationApps;
use App\Filament\Components\IntegrationAppForm;
use App\Models\IntegrationApp;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A tenant's own apps at the services its connections sign in at: for it alone,
 * beside the ones the installation gives it.
 */
class IntegrationAppResource extends Resource
{
    protected static ?string $model = IntegrationApp::class;

    protected static ?string $navigationLabel = 'Apps';

    protected static ?string $modelLabel = 'app';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(IntegrationAppForm::fields(
            collect(Integrations::TYPES)->filter(fn (array $type): bool => $type['tenants_add'])->map(fn (array $type): string => $type['label'])->all(),
        ));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => Integrations::TYPES[$state]['label'] ?? $state),
                TextColumn::make('updated_at')->label('Changed')->since(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, IntegrationApp $record): array => IntegrationAppForm::fill($data, $record))
                    ->mutateDataUsing(fn (array $data, IntegrationApp $record): array => IntegrationAppForm::save($data, $record)),
                DeleteAction::make()
                    ->modalDescription('Connections that signed in through it have to be connected again, through another app or with a token.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrationApps::route('/'),
        ];
    }
}
