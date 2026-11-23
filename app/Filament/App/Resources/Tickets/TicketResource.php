<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Tickets;

use App\Enums\ConnectionKind;
use App\Enums\TicketStyle;
use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Filament\App\Resources\Tickets\Pages\ManageTickets;
use App\Filament\Concerns\ForTenantAdmins;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Models\TicketSource;
use App\Services\Connections;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Where the ticket a pull request title names is read from: the service and its
 * credentials, and which references it answers, as one thing.
 *
 * Underneath it is still a connection and a source pointing at it, as the review
 * reads them. A connection that more than one source shares, from before the two
 * were one page, is changed for all of them when one is edited.
 */
class TicketResource extends Resource
{
    use ForTenantAdmins;

    protected static ?string $model = TicketSource::class;

    protected static ?string $modelLabel = 'ticket source';

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?string $slug = 'tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 25;

    public static function getPluralModelLabel(): string
    {
        return 'tickets';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Service')
                    ->statePath('connection')
                    ->schema(ConnectionResource::serviceFields(ConnectionKind::Tickets)),
                Section::make('Which references')
                    ->description('Which ticket a pull request title names, and so which ticket the review reads.')
                    ->schema([
                        Select::make('style')
                            ->label('References look like')
                            ->options(collect(TicketStyle::cases())->mapWithKeys(fn (TicketStyle $style): array => [$style->value => $style->text()]))
                            ->default(TicketStyle::Key->value)
                            ->required()
                            ->live(),
                        TagsInput::make('prefixes')
                            ->placeholder('PROJ')
                            ->visible(fn (Get $get): bool => $get('style') === TicketStyle::Key->value || $get('style') === TicketStyle::Key)
                            ->helperText('The prefixes this service answers, such as PROJ for PROJ-123. Empty takes any prefix.'),
                        TextInput::make('reference_prefix')
                            ->label('Look up as')
                            ->placeholder('PROJ-')
                            ->maxLength(32)
                            ->visible(fn (Get $get): bool => $get('style') === TicketStyle::Number->value || $get('style') === TicketStyle::Number)
                            ->helperText('Put in front of the number before it is looked up: with PROJ-, a title\'s #1234 is read as PROJ-1234. Empty for a tracker that numbers its tickets, like GitHub, and for Asana tasks whose name starts with the number, like "#1234 - Export the orders".'),
                        Select::make('repository_id')
                            ->label('Only for')
                            ->options(fn (): array => CurrentTenant::get()->repositories()->get()->mapWithKeys(fn ($repository): array => [$repository->id => $repository->fullName()])->all())
                            ->placeholder('Every repository')
                            ->helperText('One for a single repository is tried before the ones for every repository.'),
                        TextInput::make('position')->label('Order')->integer()->default(0)->required()
                            ->helperText('Tried from low to high; the first whose kind of reference the title carries is used.'),
                    ]),
                Section::make('Outside the review')
                    ->schema([
                        Toggle::make('readable_over_mcp')
                            ->label('Readable over MCP')
                            ->default(false)
                            ->helperText('Lets every member of the tenant read this service\'s tickets in full from their AI assistant, with the connection\'s access: also tickets they could not open in the tracker themselves. Off, they get only the reference and the link.'),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('integration.driver')->label('Service')
                    ->formatStateUsing(fn (TicketSource $record): string => rescue(fn (): string => $record->integration->driver()->label(), (string) $record->integration->driver, false)),
                TextColumn::make('style')->label('References')->badge()->formatStateUsing(fn (TicketStyle $state): string => $state->text()),
                TextColumn::make('prefixes')->badge()->placeholder('Any'),
                TextColumn::make('repository.slug')->label('Only for')->placeholder('Every repository'),
            ])
            ->recordActions([
                ...ConnectionResource::serviceActions(fn (Model $record): Connection => self::connectionOf($record)),
                EditAction::make()
                    // Secrets are never sent back to the browser.
                    ->mutateRecordDataUsing(fn (array $data, TicketSource $record): array => [
                        'connection' => ['kind' => ConnectionKind::Tickets->value, 'credentials' => []] + $record->integration->only(['driver', 'name', 'settings']),
                    ] + $data)
                    ->using(fn (TicketSource $record, array $data, Connections $connections): TicketSource => self::save($record, $data, $connections)),
                DeleteAction::make()
                    ->after(function (TicketSource $record): void {
                        // Nothing else reads its tickets now.
                        $connection = Connection::query()->find($record->connection_id);

                        // Outright, with its token: only a host's connection is kept.
                        if ($connection !== null && ! $connection->ticketSources()->exists()) {
                            $connection->remove();
                        }
                    }),
            ]);
    }

    /**
     * A new ticket source and its connection, or an edit of both.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(?TicketSource $source, array $data, Connections $connections): TicketSource
    {
        $service = (array) ($data['connection'] ?? []);
        unset($data['connection']);

        return DB::transaction(function () use ($source, $data, $service, $connections): TicketSource {
            $connection = $source === null ? new Connection(['kind' => ConnectionKind::Tickets, 'driver' => $service['driver'] ?? null]) : $source->integration;
            $connection->tenant()->associate(CurrentTenant::get());
            $connection->name = (string) $service['name'];
            $connection->settings = $service['settings'] ?? [];
            $connection->credentials = $connections->mergeCredentials($connection, (array) ($service['credentials'] ?? []));
            $connections->prepare($connection);
            $connection->save();

            $source ??= new TicketSource;
            $source->fill(['name' => $connection->name] + $data);
            $source->tenant()->associate(CurrentTenant::get());
            $source->integration()->associate($connection);
            $source->save();

            return $source;
        });
    }

    protected static function connectionOf(Model $record): Connection
    {
        return $record instanceof TicketSource ? $record->integration : throw new LogicException('Not a ticket source.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTickets::route('/'),
        ];
    }
}
