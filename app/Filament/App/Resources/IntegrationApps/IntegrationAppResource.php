<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\IntegrationApps;

use App\Connections\Integrations;
use App\Filament\App\Resources\IntegrationApps\Pages\ManageIntegrationApps;
use App\Filament\Components\IntegrationAppForm;
use App\Filament\Concerns\ForTenantAdmins;
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
    use ForTenantAdmins;

    protected static ?string $model = IntegrationApp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 31;

    public static function getNavigationLabel(): string
    {
        return __('Apps');
    }

    public static function getModelLabel(): string
    {
        return __('app');
    }

    public static function getPluralModelLabel(): string
    {
        return __('apps');
    }

    /**
     * The page's own name, rather than the model label in title case, which reads
     * oddly in Dutch.
     */
    public static function getBreadcrumb(): string
    {
        return static::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(IntegrationAppForm::fields(
            collect(Integrations::types())->filter(fn (array $type): bool => $type['tenants_add'])->map(fn (array $type): string => $type['label'])->all(),
        ));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state): string => Integrations::types()[$state]['label'] ?? $state),
                TextColumn::make('updated_at')->label(__('Changed'))->since(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, IntegrationApp $record): array => IntegrationAppForm::fill($data, $record))
                    ->mutateDataUsing(fn (array $data, IntegrationApp $record): array => IntegrationAppForm::save($data, $record)),
                DeleteAction::make()
                    ->modalDescription(__('Connections that signed in through it have to be connected again, through another app or with a token.')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrationApps::route('/'),
        ];
    }
}
