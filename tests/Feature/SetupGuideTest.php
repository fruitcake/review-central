<?php

use App\Connections\Drivers;
use App\Connections\ExplainsSetup;
use App\Enums\ConnectionKind;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\Setup;
use App\Filament\App\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\App\Resources\SourceControl\Pages\ManageSourceControl;
use App\Filament\App\Resources\Tickets\Pages\ManageTickets;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SetupCheck;
use App\SourceControl\GitHubAppDriver;
use App\SourceControl\GitHubDriver;
use App\Support\SetupGuide;
use App\Support\SetupGuides;
use App\Tickets\JiraDriver;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * Every driver of every kind, as config/review.php lists them.
 *
 * @return array<string, array{ConnectionKind, string}>
 */
function everyDriver(): array
{
    $drivers = [];

    foreach (ConnectionKind::cases() as $kind) {
        foreach (array_keys((array) config("review.drivers.{$kind->value}")) as $driver) {
            $drivers["{$kind->value} {$driver}"] = [$kind, (string) $driver];
        }
    }

    return $drivers;
}

it('says for every driver where its key is made, and how', function () {
    foreach (everyDriver() as $name => [$kind, $driver]) {
        $found = app(Drivers::class)->get($kind, $driver);

        expect($found)->toBeInstanceOf(ExplainsSetup::class, "{$name} has no guide");

        $guide = $found->setupGuide();

        expect($guide->url)->toStartWith('https://')
            ->and($guide->linkLabel)->not->toBe('')
            ->and($guide->steps)->not->toBeEmpty()
            ->and($guide->title)->not->toBe('');
    }

    expect(everyDriver())->toHaveCount(15);
});

it('asks of a GitHub token the permissions the review calls GitHub with, and no more', function () {
    $permissions = app(GitHubDriver::class)->setupGuide()->permissions;

    expect(array_keys($permissions))->toBe([
        'Contents: Read', 'Metadata: Read', 'Pull requests: Read and write', 'Issues: Read and write', 'Commit statuses: Read and write', 'Webhooks: Read and write',
    ]);
});

it('sends someone to install this installation\'s GitHub App, once there is one', function () {
    config(['services.github_app.slug' => null]);
    expect(app(GitHubAppDriver::class)->setupGuide()->url)->toBe('https://github.com/settings/installations');

    config([
        'services.github_app.id' => '1', 'services.github_app.slug' => 'acme-review', 'services.github_app.client_id' => 'client',
        'services.github_app.client_secret' => 'secret', 'services.github_app.private_key' => 'key',
    ]);
    expect(app(GitHubAppDriver::class)->setupGuide()->url)->toBe('https://github.com/apps/acme-review/installations/new');
});

it('warns that a Jira token with scopes does not reach the site, and offers signing in where it can', function () {
    config(['services.atlassian.client_id' => null, 'services.atlassian.client_secret' => null]);
    $notes = implode(' ', app(JiraDriver::class)->setupGuide()->notes);

    expect($notes)->toContain('A token with scopes does not work here')->not->toContain('Connect');

    config(['services.atlassian.client_id' => 'app', 'services.atlassian.client_secret' => 'secret']);

    expect(implode(' ', app(JiraDriver::class)->setupGuide()->notes))->toContain('**Connect**');
});

it('renders a guide with its link, steps, scopes and notes, and nothing the text may smuggle in', function () {
    $html = view('filament.setup-guide', ['guide' => new SetupGuide(
        title: 'A token',
        url: 'https://example.com/tokens',
        linkLabel: 'Tokens at Example',
        steps: ['Open **Settings**.', 'Paste it <script>alert(1)</script>.'],
        permissions: ['repo:read' => 'reads the `code`.'],
        notes: ['It expires.'],
    )])->render();

    expect($html)->toContain('href="https://example.com/tokens"', 'Tokens at Example', '<ol>', '<strong>Settings</strong>', '<code>repo:read</code>', '<code>code</code>', 'class="rc-note"', 'It expires.')
        ->not->toContain('<script>');
});

describe('in a tenant', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
        $user = User::factory()->create();
        $user->tenants()->attach($this->tenant);
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
    });

    it('offers the guide of the service picked, next to the service', function (string $page, string $driver, array $see) {
        livewire($page)
            ->mountAction('create')
            ->set('mountedActions.0.data.driver', $driver)
            ->assertActionVisible(TestAction::make('setupGuide')->schemaComponent('driver'))
            ->mountAction(TestAction::make('setupGuide')->schemaComponent('driver'))
            ->assertMountedActionModalSee($see);
    })->with([
        'GitHub' => [ManageSourceControl::class, 'github', ['https://github.com/settings/personal-access-tokens/new', 'Pull requests: Read and write', 'Commit statuses: Read and write']],
        'Bitbucket' => [ManageSourceControl::class, 'bitbucket', ['pullrequest:write', 'webhook', 'Create workspace access token']],
        'Anthropic' => [ManageAiProviders::class, 'anthropic', ['https://platform.claude.com/settings/keys', 'Create Key']],
        'OpenAI' => [ManageAiProviders::class, 'openai', ['https://platform.openai.com/api-keys', 'Models: Read']],
        'Gemini' => [ManageAiProviders::class, 'gemini', ['https://aistudio.google.com/apikey', 'GEMINI_API_KEY']],
    ]);

    it('offers the guide on a ticket source\'s connection', function () {
        livewire(ManageTickets::class)
            ->mountAction('create')
            ->set('mountedActions.0.data.connection.driver', 'jira')
            ->mountAction(TestAction::make('setupGuide')->schemaComponent('connection.driver'))
            ->assertMountedActionModalSee(['https://id.atlassian.com/manage-profile/security/api-tokens', 'Browse projects']);
    });
});

describe('for the installation', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    it('explains the Cloudflare token and the bucket\'s keys under Settings', function () {
        livewire(Settings::class)
            ->mountAction(TestAction::make('cloudflareTokenGuide')->schemaComponent('cloudflare.cloudflare_api_token'))
            ->assertMountedActionModalSee(['https://dash.cloudflare.com/profile/api-tokens', 'Workers Scripts: Edit', 'Containers: Edit', 'Account Settings: Read']);

        livewire(Settings::class)
            ->mountAction(TestAction::make('r2KeysGuide')->schemaComponent('cloudflare.r2_access_key_id'))
            ->assertMountedActionModalSee(['Object Read & Write', 'Secret Access Key']);
    });

    it('offers a guide on each optional check that is off, and has one for every name', function () {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null, 'services.github_app.id' => null]);

        $guides = collect(app(SetupCheck::class)->groups())->flatMap(fn (array $group): array => $group['checks'])->pluck('guide', 'title')->filter();

        expect($guides)->toMatchArray(['Signing in with Google' => SetupGuides::GOOGLE, 'Reviewing as a GitHub App' => SetupGuides::GITHUB_APP]);

        foreach ([SetupGuides::CLOUDFLARE_TOKEN, SetupGuides::R2_KEYS, SetupGuides::GOOGLE, SetupGuides::ASANA, SetupGuides::ATLASSIAN, SetupGuides::GITHUB_APP] as $name) {
            expect(app(SetupGuides::class)->named($name))->toBeInstanceOf(SetupGuide::class);
        }

        livewire(Setup::class)
            ->assertSee('How to get this')
            ->mountAction(TestAction::make('guide')->arguments(['guide' => SetupGuides::GITHUB_APP]))
            ->assertMountedActionModalSee(['https://github.com/settings/apps/new', url('/api/webhooks/github-app'), 'Organization → Members: Read']);
    });
});
