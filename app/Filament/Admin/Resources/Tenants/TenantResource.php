<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\TenantRole;
use App\Filament\Themes;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Currency;
use App\Services\PlatformSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->helperText('In the tenant\'s address: /app/<slug>.'),
                Select::make('users')
                    ->relationship('users', 'name')
                    ->multiple()
                    ->preload()
                    ->live()
                    ->helperText('Who may open this tenant. An administrator may open every tenant.'),
                Select::make('admins')
                    ->label('Its admins')
                    ->multiple()
                    ->options(fn (Get $get): array => User::query()->whereKey($get('users') ?? [])->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateHydrated(fn (Select $component, ?Tenant $record) => $component->state($record?->users()->wherePivot('role', TenantRole::ADMIN)->pluck('users.id')->all() ?? []))
                    // Saved after the people above, as their role in it.
                    ->dehydrated(false)
                    ->saveRelationshipsUsing(function (Tenant $record, ?array $state): void {
                        foreach ($record->users()->pluck('users.id') as $id) {
                            $record->users()->updateExistingPivot($id, ['role' => in_array($id, $state ?? []) ? TenantRole::ADMIN : TenantRole::MEMBER]);
                        }
                    })
                    ->helperText('Of the people above, who manage its connections, keys, apps, settings and members. The others are members: they read, rate and start reviews.'),
                TagsInput::make('sign_in_domains')
                    ->label('Sign-in domains')
                    ->placeholder('example.com')
                    ->nestedRecursiveRules(['regex:/^(?!-)[a-z0-9-]+(\\.[a-z0-9-]+)+$/'])
                    ->dehydrateStateUsing(fn (?array $state): ?array => $state === null || $state === [] ? null : array_values(array_unique(array_map(fn (string $domain): string => strtolower(trim($domain)), $state))))
                    ->helperText('Anyone who signs in with a Google account that one of these organisations manages gets an account and joins this tenant, without an invitation. Leave empty to let in only who is listed above.'),
                Select::make('currency')
                    ->options(Currency::NAMES)
                    ->default(fn (): string => app(PlatformSettings::class)->currency())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! app(Currency::class)->ensureRate((string) $value)) {
                            $fail('The exchange rate for this currency could not be fetched. Try again later.');
                        }
                    })
                    ->helperText('What the tenant reads its costs in. A review is costed in US dollars; another currency is converted at the latest rate of the European Central Bank.'),
                Select::make('timezone')
                    ->label('Time zone')
                    ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->default(fn (): string => app(PlatformSettings::class)->timezone())
                    ->required()
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->helperText('The zone the tenant reads its times in, and where a day begins in its overview.'),
                TextInput::make('monthly_budget')
                    ->label('Monthly budget')
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (Get $get): string => Currency::SYMBOLS[$get('currency')] ?? '$')
                    ->helperText('What the tenant means to spend on reviews in a month. Shown against the month\'s cost; nothing stops when it is reached.'),
                Fieldset::make('Colours')
                    ->columns(3)
                    ->schema(array_map(
                        fn (string $name, string $use): ColorPicker => ColorPicker::make("theme_colors.{$name}")
                            ->label(ucfirst($name))
                            ->placeholder(fn (): string => Themes::for(null)[$name])
                            ->regex(Themes::COLOUR)
                            ->validationMessages(['regex' => 'A colour as six hex digits, such as #c8d35b.'])
                            ->helperText($use),
                        array_keys(Themes::BASES),
                        Themes::BASES,
                    )),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['repositories', 'users'])
                ->withSum(['reviewRuns as cost_this_month' => fn (Builder $runs) => $runs->where('started_at', '>=', now()->startOfMonth())], 'cost')
                ->withCount(['reviewRuns as runs_this_month' => fn (Builder $runs) => $runs->where('started_at', '>=', now()->startOfMonth())]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('users_count')->label('Users')->numeric(),
                TextColumn::make('repositories_count')->label('Repositories')->numeric(),
                TextColumn::make('runs_this_month')->label('Reviews this month')->numeric(),
                TextColumn::make('cost_this_month')->label('Cost this month')->state(fn (Tenant $record): string => Currency::show($record->getAttribute('cost_this_month'), $record)),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Tenant $record): string => url("/app/{$record->slug}")),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Deletes the tenant with its connections, repositories and review history.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenants::route('/'),
        ];
    }
}
