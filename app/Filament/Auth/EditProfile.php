<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PersonalConnections;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use LogicException;

/**
 * The profile, with who the user is on the hosts: which pull requests are theirs
 * to act on is read from that.
 */
class EditProfile extends BaseEditProfile
{
    protected static bool $shouldRegisterNavigation = false;

    /**
     * In a tenant it keeps the sidebar and the way back; without one, for a user in
     * no tenant, there is no sidebar to show.
     */
    public static function isSimple(): bool
    {
        return Filament::getTenant() === null;
    }

    /**
     * The tenant's page; the panel's own profile, without a tenant, is reached
     * through the panel's getProfileUrl().
     */
    public static function getRouteName(?Panel $panel = null): string
    {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        return $panel->generateRouteName(static::getRelativeRouteName($panel));
    }

    /**
     * The profile in the tenant open now, or else in the user's first one, or else
     * the one without a tenant.
     */
    public static function urlForCurrentUser(): ?string
    {
        $panel = Filament::getPanel('app');
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant() ?? ($user instanceof User ? $user->getDefaultTenant($panel) : null);

        return $tenant instanceof Tenant ? static::getUrl(panel: 'app', tenant: $tenant) : $panel->getProfileUrl();
    }

    /**
     * Back where the user came from, or else to the tenant the profile is in rather
     * than the user's first one.
     */
    protected function getCancelFormAction(): Action
    {
        $url = Filament::getUrl(Filament::getTenant());

        return parent::getCancelFormAction()->alpineClickHandler(
            FilamentView::hasSpaMode($url)
                ? 'document.referrer ? window.history.back() : Livewire.navigate('.Js::from($url).')'
                : 'document.referrer ? window.history.back() : (window.location.href = '.Js::from($url).')',
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                self::accountsSection(),
                TagsInput::make('source_accounts')
                    ->label(fn (): string => self::hasConnected() ? 'Other accounts on the hosts' : 'Your accounts on the hosts')
                    ->placeholder('A nickname, login or account ID')
                    ->nestedRecursiveRules(['string', 'max:120'])
                    ->helperText('Your nickname or login on a host whose account you did not connect, as its pull requests name you: "With me" under Pull requests lists the ones whose turn is yours. They only tell which pull requests are yours, and give no access. Without any account, your name here is matched to the name the host shows.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                self::assistantsSection(),
            ]);
    }

    /**
     * Whether the user connected an account at any host.
     */
    private static function hasConnected(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->personalConnections()->exists();
    }

    /**
     * The user's own accounts at Bitbucket and GitHub, one line each: whether it
     * is connected, and the buttons. Only the hosts the installation is
     * registered at: a Bitbucket consumer, a GitHub App.
     */
    private static function accountsSection(): Section
    {
        $hosts = array_keys(PersonalConnections::HOSTS);

        return Section::make('Bitbucket and GitHub')
            ->description('Connect your own account. You then sign in with it, the pull requests that are yours show under "With me", and your replies to findings are written as you instead of by the team\'s connection with your name above them. Review Central uses it for nothing else.')
            ->visible(fn (): bool => collect($hosts)->contains(fn (string $host): bool => PersonalConnections::available($host)))
            ->schema(array_map(self::accountEntry(...), $hosts));
    }

    /**
     * One host's line: its state, what connecting it does there, and the buttons.
     */
    private static function accountEntry(string $host): TextEntry
    {
        $connections = app(PersonalConnections::class);
        $name = PersonalConnections::HOSTS[$host];
        $user = fn (): User => Filament::auth()->user() instanceof User ? Filament::auth()->user() : throw new LogicException('Not signed in.');

        return TextEntry::make("{$host}_account")
            ->label($name)
            ->inlineLabel()
            ->visible(fn (): bool => PersonalConnections::available($host))
            ->state(function () use ($connections, $user, $host): string {
                $connection = $connections->of($user(), $host);

                return match (true) {
                    $connection === null && $connections->signsIn($user(), $host) => 'Signs you in. Connect it to reply as you.',
                    $connection === null => 'Not connected',
                    ($problem = $connections->problem($user(), $host)) !== null => $problem,
                    default => 'Connected as '.($connection->nickname ?? $connection->account_id),
                };
            })
            ->weight(fn (): ?FontWeight => $connections->of($user(), $host) === null ? null : FontWeight::SemiBold)
            ->belowContent($host === PersonalConnections::GITHUB
                ? 'Replies show your name with the app\'s badge, on repositories reviewed through the GitHub App.'
                : 'Bitbucket lets it comment on every pull request you can.')
            ->afterContent([
                Action::make('connect'.ucfirst($host))
                    ->label(fn (): string => $connections->of($user(), $host) === null ? 'Connect' : 'Connect again')
                    ->icon(Heroicon::OutlinedLink)
                    ->size('sm')
                    ->color(fn (): string => $connections->of($user(), $host) === null ? 'primary' : 'gray')
                    // Back to this profile, in the tenant it is open in.
                    ->url(fn (): string => route('connections.personal.redirect', array_filter(['host' => $host, 'tenant' => Filament::getTenant()?->getAttribute('slug')]))),
                Action::make('disconnect'.ucfirst($host))
                    ->label('Disconnect')
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (): bool => $connections->of($user(), $host) !== null || $connections->signsIn($user(), $host))
                    ->requiresConfirmation()
                    ->modalHeading("Disconnect {$name}?")
                    ->modalDescription($host === PersonalConnections::GITHUB
                        ? 'Your replies are written by the app again, with your name above them, and the account no longer signs you in.'
                        : 'Your replies are written by the team\'s connection again, with your name above them, and the account no longer signs you in.')
                    ->action(function () use ($connections, $user, $host, $name): void {
                        $connections->disconnect($user(), $host);
                        Notification::make()->title("{$name} is disconnected")->success()->send();
                    }),
            ]);
    }

    /**
     * Where to point an AI assistant at the MCP server: the assistant signs the
     * user in itself, so the addresses are all there is to set.
     */
    private static function assistantsSection(): Section
    {
        return Section::make('In Claude and other AI assistants')
            ->description('An assistant reads the reviews and acts on them as you, and no more than your account may. Add the address, and it signs you in here.')
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('mcp_user')
                    ->label('For a chat')
                    ->state(url('mcp/user'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->helperText('In Claude: Settings → Connectors → Add custom connector. Your tenants, the open pull requests and whose turn each is, the reviews and their findings.'),
                TextEntry::make('mcp_agent')
                    ->label('For a coding agent, such as Claude Code')
                    ->state('claude mcp add --transport http review-central '.url('mcp/agent'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->helperText('Run it in the checkout. The agent reads the ticket and the review\'s instructions, can review a change before it is pushed, and settle a review\'s findings.'),
            ]);
    }
}
