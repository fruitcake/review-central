<?php

use App\Connections\Drivers;
use App\Connections\Integrations;
use App\Enums\ConnectionKind;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Models\Connection;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config([
        'services.asana.client_id' => null, 'services.asana.client_secret' => null,
        'services.atlassian.client_id' => null, 'services.atlassian.client_secret' => null,
        'services.google.client_id' => null, 'services.google.client_secret' => null,
        'services.github_app' => ['id' => null, 'slug' => null, 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null],
    ]);
});

it('takes the apps from the platform\'s settings, where the environment sets none, and clears one cleared there', function () {
    $settings = app(PlatformSettings::class);
    $settings->save([PlatformSettings::ASANA_CLIENT_ID => 'asana-app', PlatformSettings::ASANA_CLIENT_SECRET => 'asana-secret']);
    $settings->applyIntegrations();

    expect(config('services.asana.client_id'))->toBe('asana-app')
        ->and(config('services.asana.client_secret'))->toBe('asana-secret')
        // The secret is kept encrypted.
        ->and(PlatformSetting::query()->find(PlatformSettings::ASANA_CLIENT_SECRET)->getRawOriginal('value'))->not->toContain('asana-secret');

    $settings->save([PlatformSettings::ASANA_CLIENT_ID => null]);
    $settings->applyIntegrations();

    expect(config('services.asana.client_id'))->toBeNull();

    // What .env sets goes first, and the setting says so.
    config(['services.atlassian.client_id' => 'from-env']);
    $settings->save([PlatformSettings::ATLASSIAN_CLIENT_ID => 'from-settings']);
    $settings->applyIntegrations();

    expect(config('services.atlassian.client_id'))->toBe('from-env')
        ->and($settings->setByEnvironment(PlatformSettings::ATLASSIAN_CLIENT_ID))->toBe('ATLASSIAN_CLIENT_ID');
});

it('lets an administrator fill in the apps under Settings, and never sends a secret back', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(Settings::class)
        ->fillForm([
            PlatformSettings::GITHUB_APP_ID => '123', PlatformSettings::GITHUB_APP_SLUG => 'acme-review', PlatformSettings::GITHUB_APP_CLIENT_ID => 'Iv1.abc',
            PlatformSettings::GITHUB_APP_CLIENT_SECRET => 'client-secret', PlatformSettings::GITHUB_APP_WEBHOOK_SECRET => 'hook-secret',
            PlatformSettings::GITHUB_APP_PRIVATE_KEY => "-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----",
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet([PlatformSettings::GITHUB_APP_SLUG => 'acme-review', PlatformSettings::GITHUB_APP_CLIENT_SECRET => null, PlatformSettings::GITHUB_APP_PRIVATE_KEY => null]);

    app(PlatformSettings::class)->applyIntegrations();

    expect(config('services.github_app.slug'))->toBe('acme-review')
        ->and(config('services.github_app.private_key'))->toStartWith('-----BEGIN RSA PRIVATE KEY-----');

    // Not a key at all.
    livewire(Settings::class)
        ->fillForm([PlatformSettings::GITHUB_APP_PRIVATE_KEY => 'not a key'])
        ->call('save')
        ->assertHasFormErrors([PlatformSettings::GITHUB_APP_PRIVATE_KEY]);
});

it('offers signing in through an app only to a tenant it is on for', function () {
    config(['services.asana.client_id' => 'asana-app', 'services.asana.client_secret' => 'asana-secret']);
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $this->actingAs($user);
    $asana = Connection::query()->create(['tenant_id' => $tenant->id, 'kind' => 'tickets', 'driver' => 'asana', 'name' => 'Asana', 'credentials' => [], 'settings' => ['workspace' => '1200']]);
    Filament::setTenant($tenant);

    livewire(ManageConnections::class)->assertActionVisible(TestAction::make('connect')->table($asana));

    $tenant->update(['integrations_off' => ['asana']]);

    livewire(ManageConnections::class)->assertActionHidden(TestAction::make('connect')->table($asana));
    $this->get("/connections/{$asana->id}/oauth")->assertNotFound();
    expect(Integrations::unavailable(app(Drivers::class)->get(ConnectionKind::Tickets, 'asana'), 'Asana'))->toContain('not on for this team');
});

it('offers the GitHub App as a connection only where it is on for the tenant', function () {
    config(['services.github_app' => ['id' => '1', 'slug' => 'review', 'client_id' => 'Iv1', 'client_secret' => 'cs', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nabc", 'webhook_secret' => 'hook']]);
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $this->actingAs($user);
    Filament::setTenant($tenant);

    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->toHaveKey('github-app');

    $tenant->update(['integrations_off' => ['github_app']]);

    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->not->toHaveKey('github-app');
});

it('turns an app off for a tenant on the tenant, keeping what is off', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $tenant = Tenant::factory()->create();

    livewire(ManageTenants::class)
        ->callAction(TestAction::make('edit')->table($tenant), data: ['integrations_off' => ['asana', 'github_app']])
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->integrations_off)->toBe(['jira'])
        ->and($tenant->allowsIntegration('jira'))->toBeFalse()
        ->and($tenant->allowsIntegration('asana'))->toBeTrue();
});

it('creates a tenant with every app on', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ManageTenants::class)
        ->callAction('create', data: ['name' => 'Beta', 'slug' => 'beta'])
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'beta')->sole();

    expect($tenant->integrations_off)->toBeNull()
        ->and($tenant->allowsIntegration('asana'))->toBeTrue()
        ->and($tenant->allowsIntegration('github_app'))->toBeTrue();
});
