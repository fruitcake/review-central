<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Enums\LandingPage;
use App\Enums\Language;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Landing;
use App\Services\PersonalConnections;
use App\Services\PlatformSettings;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
                    ->label(fn (): string => self::hasConnected() ? __('Other accounts on the hosts') : __('Your accounts on the hosts'))
                    ->placeholder(__('A nickname, login or account ID'))
                    ->nestedRecursiveRules(['string', 'max:120'])
                    ->helperText(__('Your nickname or login on a host whose account you did not connect, as its pull requests name you: "With me" under Pull requests lists the ones whose turn is yours. They only tell which pull requests are yours, and give no access. Without any account, your name here is matched to the name the host shows.')),
                Select::make('language')
                    ->label(__('Language'))
                    ->options(Language::options())
                    ->enum(Language::class)
                    // Not the installation's language by name: the user's own has replaced it in config by now.
                    ->placeholder(__('Installation default'))
                    ->helperText(__('The language you read Review Central in. Platform admin and email stay in English.')),
                self::landingSection(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                self::assistantsSection(),
            ]);
    }

    /**
     * Where an admin lands after signing in, and in which tenant. A member lands
     * on what waits for them, in the first of their tenants.
     */
    private static function landingSection(): Section
    {
        $user = fn (): ?User => Filament::auth()->user() instanceof User ? Filament::auth()->user() : null;

        return Section::make(__('After signing in'))
            ->visible(fn (): bool => ($current = $user()) !== null && app(Landing::class)->canChoose($current))
            ->schema([
                Select::make('landing_page')
                    ->label(__('Start on'))
                    ->options(fn (): array => LandingPage::options(withPlatform: (bool) $user()?->is_admin))
                    ->enum(LandingPage::class)
                    ->placeholder(fn (): string => $user()?->is_admin ? LandingPage::Platform->label() : __('As your role in the tenant: its dashboard for an admin')),
                Select::make('landing_tenant_id')
                    ->label(__('In'))
                    ->options(fn (): array => ($current = $user()) === null ? [] : $current->getTenants(Filament::getPanel('app'))->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder(__('The first, by name'))
                    ->helperText(__('The tenant you land in, and that the panel opens on.')),
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
     * registered at: a Bitbucket OAuth client, a GitHub App.
     */
    private static function accountsSection(): Section
    {
        $hosts = array_keys(PersonalConnections::HOSTS);

        return Section::make(__('Bitbucket and GitHub'))
            ->description(__('Connect your own account. You then sign in with it, the pull requests that are yours show under "With me", and your replies to findings are written as you instead of by the team\'s connection with your name above them. Review Central uses it for nothing else.'))
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
                    $connection === null && $connections->signsIn($user(), $host) => __('Signs you in. Connect it to reply as you.'),
                    $connection === null => __('Not connected'),
                    ($problem = $connections->problem($user(), $host)) !== null => $problem,
                    default => __('Connected as :account', ['account' => $connection->nickname ?? $connection->account_id]),
                };
            })
            ->weight(fn (): ?FontWeight => $connections->of($user(), $host) === null ? null : FontWeight::SemiBold)
            ->belowContent($host === PersonalConnections::GITHUB
                ? __('Replies show your name with the app\'s badge, on repositories reviewed through the GitHub App.')
                : __('Bitbucket lets it comment on every pull request you can.'))
            ->afterContent([
                Action::make('connect'.ucfirst($host))
                    ->label(fn (): string => $connections->of($user(), $host) === null ? __('Connect') : __('Connect again'))
                    ->icon(Heroicon::OutlinedLink)
                    ->size('sm')
                    ->color(fn (): string => $connections->of($user(), $host) === null ? 'primary' : 'gray')
                    // Back to this profile, in the tenant it is open in.
                    ->url(fn (): string => route('connections.personal.redirect', array_filter(['host' => $host, 'tenant' => Filament::getTenant()?->getAttribute('slug')]))),
                Action::make('disconnect'.ucfirst($host))
                    ->label(__('Disconnect'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (): bool => $connections->of($user(), $host) !== null || $connections->signsIn($user(), $host))
                    ->requiresConfirmation()
                    ->modalHeading(__('Disconnect :host?', ['host' => $name]))
                    ->modalDescription($host === PersonalConnections::GITHUB
                        ? __('Your replies are written by the app again, with your name above them, and the account no longer signs you in.')
                        : __('Your replies are written by the team\'s connection again, with your name above them, and the account no longer signs you in.'))
                    ->action(function () use ($connections, $user, $host, $name): void {
                        $connections->disconnect($user(), $host);
                        Notification::make()->title(__(':host is disconnected', ['host' => $name]))->success()->send();
                    }),
            ]);
    }

    /**
     * Where to point an AI assistant at the MCP server: the assistant signs the
     * user in itself, so the addresses are all there is to set.
     */
    private static function assistantsSection(): Section
    {
        return Section::make(__('In Claude and other AI assistants'))
            ->description(__('An assistant reads the reviews and acts on them as you, and no more than your account may. Add the address, and it signs you in here.'))
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('mcp_user')
                    ->label(__('For a chat'))
                    ->state(url('mcp/user'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->helperText(fn (): string => __('In Claude: Settings → Connectors → Add custom connector, named :name. Your tenants, the open pull requests and whose turn each is, the reviews and their findings.', ['name' => app(PlatformSettings::class)->mcpName()])),
                TextEntry::make('mcp_agent')
                    ->label(__('For a coding agent, such as Claude Code'))
                    ->state(fn (): string => 'claude mcp add --transport http '.app(PlatformSettings::class)->mcpName().' '.url('mcp/agent'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->helperText(__('Run it in the checkout. The agent reads the ticket and the review\'s instructions, can review a change before it is pushed, and settle a review\'s findings.')),
            ]);
    }
}
