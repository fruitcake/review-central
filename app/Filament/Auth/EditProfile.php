<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\Tenant;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The profile, with who the user is on the hosts: which pull requests are theirs
 * to act on is read from that.
 */
class EditProfile extends BaseEditProfile
{
    protected static bool $shouldRegisterNavigation = false;

    /**
     * A page of a tenant, so it keeps the sidebar and the way back to the tenant.
     */
    public static function isSimple(): bool
    {
        return false;
    }

    /**
     * Registered as a page, so not under the panel's auth routes.
     */
    public static function getRouteName(?Panel $panel = null): string
    {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        return $panel->generateRouteName(static::getRelativeRouteName($panel));
    }

    /**
     * The profile in the tenant open now, or else in the user's first one; null for
     * a user in no tenant.
     */
    public static function urlForCurrentUser(): ?string
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant()
            ?? ($user instanceof User ? $user->getTenants(Filament::getPanel('app'))->first() : null);

        return $tenant instanceof Tenant ? static::getUrl(panel: 'app', tenant: $tenant) : null;
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
                self::assistantsSection(),
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
