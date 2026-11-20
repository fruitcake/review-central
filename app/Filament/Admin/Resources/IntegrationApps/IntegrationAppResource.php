<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IntegrationApps;

use App\Connections\Integrations;
use App\Filament\Admin\Resources\IntegrationApps\Pages\ManageIntegrationApps;
use App\Filament\Components\IntegrationAppForm;
use App\Models\IntegrationApp;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The apps a person signs in at a service through: the platform's, for every
 * tenant or those picked, and the ones tenants added for themselves.
 */
class IntegrationAppResource extends Resource
{
    protected static ?string $model = IntegrationApp::class;

    protected static ?string $navigationLabel = 'Apps';

    protected static ?string $modelLabel = 'app';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 85;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...IntegrationAppForm::fields(fn (Get $get, ?IntegrationApp $record): array => self::types($record)),
            Toggle::make('for_all_tenants')
                ->label('For every tenant')
                ->live()
                ->visible(fn (Get $get): bool => filled($get('type')) && $get('type') !== 'google')
                ->helperText('Off: only for the tenants picked below. A new app is for none until it is given to some.'),
            Select::make('tenants')
                ->label('For these tenants')
                ->relationship('tenants', 'name')
                ->multiple()
                ->preload()
                ->visible(fn (Get $get): bool => filled($get('type')) && $get('type') !== 'google' && ! $get('for_all_tenants')),
        ]);
    }

    /**
     * The types that can be added: one of a single type only while there is none,
     * in the list or in .env.
     *
     * @return array<string, string>
     */
    public static function types(?IntegrationApp $record = null): array
    {
        $taken = IntegrationApp::query()->whereNull('tenant_id')->whereIn('type', collect(Integrations::TYPES)->filter(fn (array $type): bool => $type['single'])->keys())
            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
            ->pluck('type')
            ->all();

        // Nor one .env sets: that one goes first, and a second would never be used.
        $fromEnv = collect(Integrations::TYPES)->filter(fn (array $type, string $key): bool => $type['single'] && Integrations::fromEnv($key) !== null)->keys()->all();

        return collect(Integrations::TYPES)->except([...$taken, ...$fromEnv])->map(fn (array $type): string => $type['label'])->all();
    }

    /**
     * One of the platform's: signing in with Google is for everyone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function platform(array $data, ?IntegrationApp $record = null): array
    {
        $type = $data['type'] ?? $record?->type;

        return ['tenant_id' => null, 'for_all_tenants' => $type === 'google' || (bool) ($data['for_all_tenants'] ?? false)] + $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tenant', 'tenants'])->orderByRaw('tenant_id is not null')->orderBy('type')->orderBy('name'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => Integrations::TYPES[$state]['label'] ?? $state),
                TextColumn::make('for')
                    ->label('For')
                    ->state(fn (IntegrationApp $record): string => match (true) {
                        $record->tenant !== null => "{$record->tenant->name}'s own",
                        $record->type === 'google' => 'Everyone, to sign in',
                        $record->for_all_tenants => 'Every tenant',
                        $record->tenants->isEmpty() => 'No tenant yet',
                        default => $record->tenants->pluck('name')->implode(', '),
                    }),
                TextColumn::make('updated_at')->label('Changed')->since(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (IntegrationApp $record): bool => $record->tenant_id === null)
                    ->mutateRecordDataUsing(fn (array $data, IntegrationApp $record): array => IntegrationAppForm::fill($data, $record))
                    ->mutateDataUsing(fn (array $data, IntegrationApp $record): array => self::platform(IntegrationAppForm::save($data, $record), $record)),
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
