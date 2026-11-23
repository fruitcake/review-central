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
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                TagsInput::make('source_accounts')
                    ->label('Your accounts on the hosts')
                    ->placeholder('A nickname, login or account ID')
                    ->nestedRecursiveRules(['string', 'max:120'])
                    ->helperText('Your Bitbucket nickname or account ID and your GitHub login, as the pull requests name you: "With me" under Pull requests lists the ones whose turn is yours. Without any, your name here is matched to the name the host shows.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                self::bitbucketSection(),
                self::assistantsSection(),
            ]);
    }

    /**
     * The user's own Bitbucket account, which what they write on a pull request
     * through Review Central is written as. Only where the installation is
     * registered at Bitbucket.
     */
    private static function bitbucketSection(): Section
    {
        $connections = app(PersonalConnections::class);
        $user = fn (): User => Filament::auth()->user() instanceof User ? Filament::auth()->user() : throw new LogicException('Not signed in.');

        return Section::make('Your Bitbucket account')
            ->description('Connected, your replies to findings on Bitbucket pull requests are written as you, and no longer by the team\'s connection with your name above them. Review Central uses it for nothing else, though Bitbucket lets it write on every pull request you can.')
            ->visible(fn (): bool => PersonalConnections::available())
            ->schema([
                TextEntry::make('bitbucket_account')
                    ->hiddenLabel()
                    ->state(function () use ($connections, $user): string {
                        $connection = $connections->of($user());

                        return match (true) {
                            $connection === null => 'Not connected.',
                            ($problem = $connections->problem($user())) !== null => $problem,
                            default => 'Connected as '.($connection->nickname ?? $connection->account_id).'.',
                        };
                    }),
                Actions::make([
                    Action::make('connectBitbucket')
                        ->label(fn (): string => $connections->of($user()) === null ? 'Connect' : 'Connect again')
                        ->icon(Heroicon::OutlinedLink)
                        // Back to this profile, in the tenant it is open in.
                        ->url(fn (): string => route('connections.bitbucket.redirect', array_filter(['tenant' => Filament::getTenant()?->getAttribute('slug')]))),
                    Action::make('disconnectBitbucket')
                        ->label('Disconnect')
                        ->color('gray')
                        ->visible(fn (): bool => $connections->of($user()) !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Your replies are written by the team\'s connection again, with your name above them.')
                        ->action(function () use ($connections, $user): void {
                            $connections->disconnect($user());
                            Notification::make()->title('Bitbucket is disconnected')->success()->send();
                        }),
                ])->key('bitbucket_actions'),
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
