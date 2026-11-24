<?php

namespace App\Filament\App\Resources\Connections;

use App\Connections\ConnectionDriver;
use App\Connections\ConnectsWithOAuth;
use App\Connections\Drivers;
use App\Connections\ExplainsSetup;
use App\Connections\Integrations;
use App\Connections\OAuthApp;
use App\Connections\OAuthTokens;
use App\Connections\SourceControlDriver;
use App\Enums\ConnectionKind;
use App\Exceptions\ConnectionFailed;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\Components\SetupGuideAction;
use App\Filament\Concerns\ForTenantAdmins;
use App\Models\Connection;
use App\Services\Connections;
use App\SourceControl\GitDriver;
use App\Support\SetupGuide;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A tenant's connections to a service, one kind to a page: where its repositories
 * are (SourceControlResource) and whose AI reviews run on (AiProviderResource).
 * Where its tickets are is on Tickets. This page itself keeps what none of them
 * shows.
 */
class ConnectionResource extends Resource
{
    use ForTenantAdmins;

    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 30;

    /** The kind of connection a page of them is for; null for the ones no other page shows. */
    protected static ?ConnectionKind $kind = null;

    protected static ?string $navigationLabel = 'Other connections';

    /**
     * Source control and AI providers have pages of their own, and tickets are on
     * Tickets. Left here: a ticket connection no ticket source uses, from before
     * the two were one page, so it is not lost. Shown only while there is one.
     */
    public static function kind(): ?ConnectionKind
    {
        return static::$kind;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::$kind !== null || rescue(fn (): bool => static::getEloquentQuery()->exists(), false, false);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return static::$kind !== null
            ? $query->where('kind', static::$kind)
            : $query->where('kind', ConnectionKind::Tickets)->whereDoesntHave('ticketSources');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::serviceFields(static::$kind))->columns(1);
    }

    /**
     * The fields of a connection: what kind, which service, its name, and the
     * service's own credentials and settings. For a kind that is fixed, as on
     * Tickets, the kind is not asked.
     *
     * @return array<int, Component>
     */
    public static function serviceFields(?ConnectionKind $fixed = null): array
    {
        $kindOf = fn (Get $get): ?ConnectionKind => $fixed ?? ($get('kind') instanceof ConnectionKind ? $get('kind') : ConnectionKind::tryFrom((string) $get('kind')));

        $driver = function (Get $get) use ($kindOf): ?ConnectionDriver {
            $kind = $kindOf($get);
            $name = $get('driver');

            return $kind !== null && is_string($name) && config("review.drivers.{$kind->value}.{$name}") !== null
                ? app(Drivers::class)->get($kind, $name)
                : null;
        };

        $settings = fn (Get $get): array => [...$driver($get)?->settingFields() ?? [], ...self::webhookFields($driver($get)), ...self::sandboxFields($driver($get))];

        return [
            $fixed !== null
                ? Hidden::make('kind')->default($fixed->value)
                : Select::make('kind')
                    // Tickets are added under Tickets, together with the references they answer.
                    ->options(fn (string $operation): array => collect(ConnectionKind::cases())
                        ->reject(fn (ConnectionKind $kind): bool => $operation === 'create' && $kind === ConnectionKind::Tickets)
                        ->mapWithKeys(fn (ConnectionKind $kind): array => [$kind->value => $kind->text()])
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('driver', null))
                    // What a connection is cannot change once repositories or sources use it.
                    ->disabledOn('edit'),
            Select::make('driver')
                ->label('Service')
                ->options(function (Get $get) use ($kindOf): array {
                    $kind = $kindOf($get);

                    return $kind === null ? [] : app(Drivers::class)->options($kind);
                })
                ->required()
                ->live()
                ->afterStateUpdated(function (Select $component, Set $set, Get $get, ?string $state) use ($driver): void {
                    if (blank($get('name')) && $state !== null) {
                        $set('name', $driver($get)?->label());
                    }

                    // The service's fields appear now, after the form was
                    // filled. Without state of their own the browser has
                    // nothing to write what is typed into, and they arrive empty.
                    foreach (['credentials', 'settings'] as $key) {
                        $section = $component->getContainer()->getComponent($key);

                        if ($section instanceof Section) {
                            $section->getChildSchema()->fill();
                        }
                    }
                })
                ->disabledOn('edit')
                // Where the service's key or token is made, and what it needs, without leaving the form.
                ->hintAction(SetupGuideAction::make(fn (Get $get): ?SetupGuide => ($found = $driver($get)) instanceof ExplainsSetup ? $found->setupGuide() : null)),
            TextInput::make('name')->required()->maxLength(255),
            Section::make('Credentials')
                ->description('Stored encrypted, and never shown again.')
                ->key('credentials')
                ->statePath('credentials')
                ->schema(fn (Get $get): array => $driver($get)?->credentialFields() ?? [])
                ->visible(fn (Get $get): bool => ($driver($get)?->credentialFields() ?? []) !== []),
            Section::make('Settings')
                ->key('settings')
                ->statePath('settings')
                ->schema(fn (Get $get): array => $settings($get))
                ->visible(fn (Get $get): bool => $settings($get) !== []),
        ];
    }

    /**
     * For a source control host: whether a review in a Cloudflare sandbox clones
     * the repository there, or the worker clones it and sends it through the bucket.
     *
     * @return array<int, Toggle>
     */
    protected static function sandboxFields(?ConnectionDriver $driver): array
    {
        if (! $driver instanceof SourceControlDriver) {
            return [];
        }

        return [
            Toggle::make('clone_in_sandbox')
                ->label('Clone in the Cloudflare sandbox')
                ->default(false)
                ->helperText('For reviews on the Cloudflare runner. On, the sandbox clones the repository itself, through the Worker: the token stays in the Worker, which adds it only to reading this repository, never to a push. The worker then clones nothing and sends only the review\'s own files through the bucket. Off, the worker clones and the checkout goes through the bucket. The host must be reachable from Cloudflare over HTTPS. A public repository on GitHub or Bitbucket, cloned without a token, is always cloned in the sandbox.'),
        ];
    }

    /**
     * For a host whose webhook tells of every repository of the workspace: whether
     * one not added yet is added by it. A plain git server sends none.
     *
     * @return array<int, Toggle>
     */
    protected static function webhookFields(?ConnectionDriver $driver): array
    {
        if (! $driver instanceof SourceControlDriver || $driver instanceof GitDriver) {
            return [];
        }

        return [
            Toggle::make('add_repositories')
                ->label('Add new repositories from webhooks')
                ->default(false)
                ->live()
                ->helperText('A repository the webhook tells of that is not here yet is added: it shows in the overview, and its pull requests are kept. One that was removed here is not added again.'),
            Toggle::make('review_added_repositories')
                ->label('Review them by themselves')
                ->default(false)
                ->visible(fn (Get $get): bool => (bool) $get('add_repositories'))
                ->helperText('On, an added repository\'s pull requests are reviewed by themselves, starting with the one that told of it, as the repository\'s own Reviewed toggle does. Off, someone starts each review from Pull requests until Reviewed is turned on.'),
        ];
    }

    /**
     * Testing a connection, and signing in at its service where this installation
     * is registered there. For a record that is a connection, or that has one.
     *
     * @param  Closure(Model): Connection  $of
     * @return array<int, Action>
     */
    public static function serviceActions(Closure $of): array
    {
        return [
            Action::make('test')
                ->icon(Heroicon::OutlinedSignal)
                ->action(function (Model $record, Connections $connections) use ($of): void {
                    try {
                        $connections->test($of($record));

                        Notification::make()->title('The connection works')->success()->send();
                    } catch (ConnectionFailed $exception) {
                        Notification::make()->title('The connection failed')->body($exception->getMessage())->danger()->send();
                    }
                }),
            // For a service this installation is registered with: signing in
            // there, instead of a token.
            Action::make('connect')
                ->label(fn (Model $record): string => OAuthTokens::has($of($record)) ? 'Connect again' : 'Connect')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->visible(fn (Model $record): bool => rescue(fn (): bool => ($driver = $of($record)->driver()) instanceof ConnectsWithOAuth && Integrations::offered($driver, $of($record)->tenant), false, false))
                // With several apps for this team, which one to sign in through.
                ->schema(fn (Model $record): ?array => count($apps = self::appsFor($of($record))) > 1 ? [
                    Select::make('app')
                        ->label('Sign in through')
                        ->options(collect($apps)->map(fn (OAuthApp $app): string => $app->name)->all())
                        ->default((string) ($of($record)->setting('oauth_app') ?? array_key_first($apps)))
                        ->required(),
                ] : null)
                ->modalSubmitActionLabel('Continue')
                ->action(fn (Model $record, array $data, $livewire) => $livewire->redirect(route('connections.oauth.redirect', array_filter(['connection' => $of($record), 'app' => $data['app'] ?? null])))),
        ];
    }

    /**
     * The apps a connection can sign in through, for its tenant.
     *
     * @return array<string, OAuthApp>
     */
    protected static function appsFor(Connection $connection): array
    {
        $driver = rescue(fn () => $connection->driver(), null, false);

        return $driver instanceof ConnectsWithOAuth ? Integrations::available(Integrations::TYPE_OF[$driver->oauthIntegration()], $connection->tenant) : [];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('driver')->label('Service')
                    ->formatStateUsing(fn (Connection $record): string => rescue(fn (): string => $record->driver()->label(), $record->driver, false)),
                // Whether deliveries arrive, which is what starts a review.
                TextColumn::make('webhook_received_at')->label('Webhook')
                    ->state(fn (Connection $record): ?string => $record->kind === ConnectionKind::SourceControl ? ($record->webhook_received_at?->diffForHumans() ?? 'Nothing received yet') : null)
                    ->color(fn (Connection $record): ?string => $record->kind === ConnectionKind::SourceControl && $record->webhook_received_at === null ? 'danger' : null)
                    ->placeholder('—')
                    ->visible(static::$kind === ConnectionKind::SourceControl),
                ...static::extraColumns(),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordActions([
                ...self::serviceActions(fn (Model $record): Connection => $record instanceof Connection ? $record : throw new LogicException('Not a connection.')),
                Action::make('webhook')
                    ->icon(Heroicon::OutlinedBolt)
                    ->visible(fn (Connection $record): bool => $record->webhook_key !== null)
                    ->modalHeading('Webhook')
                    ->modalDescription(fn (Connection $record): string => ($driver = $record->driver()) instanceof SourceControlDriver ? $driver->webhookHelp() : '')
                    ->schema(fn (Connection $record, Connections $connections): array => [
                        TextEntry::make('url')->label('URL')->state($connections->webhookUrl($record))->copyable(),
                        TextEntry::make('secret')->state($record->webhook_secret)->copyable()->fontFamily('mono'),
                    ])
                    ->modalSubmitActionLabel('Add it for me')
                    ->modalCancelActionLabel('Close')
                    ->action(function (Connection $record, Connections $connections): void {
                        try {
                            $connections->installWebhook($record);

                            Notification::make()->title('The webhook is in place')->body('It delivers for every repository of this connection.')->success()->send();
                        } catch (ConnectionFailed $exception) {
                            Notification::make()->title('The webhook could not be added')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                static::editAction(),
                DeleteAction::make()
                    ->before(function (Connection $record, DeleteAction $action): void {
                        // Its repositories would be left without a way to reach their host.
                        if ($record->repositories()->exists()) {
                            Notification::make()
                                ->title('This connection is still in use')
                                ->body('Remove its repositories first, or move them to another connection to the same host. Their history stays either way.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    })
                    // A source control connection is kept for the history of its removed repositories.
                    ->using(fn (Connection $record) => $record->remove()),
            ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    protected static function extraColumns(): array
    {
        return [];
    }

    protected static function editAction(): EditAction
    {
        return EditAction::make()
            // Secrets are never sent back to the browser.
            ->mutateRecordDataUsing(fn (array $data): array => ['credentials' => []] + $data)
            ->mutateDataUsing(fn (array $data, Connection $record, Connections $connections): array => [
                'credentials' => $connections->mergeCredentials($record, $data['credentials'] ?? []),
            ] + $data);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageConnections::route('/'),
        ];
    }
}
