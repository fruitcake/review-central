<?php

use App\Connections\Drivers;
use App\Connections\Integrations;
use App\Connections\OAuthTokens;
use App\Enums\ConnectionKind;
use App\Filament\Admin\Resources\IntegrationApps\IntegrationAppResource;
use App\Filament\Admin\Resources\IntegrationApps\Pages\ManageIntegrationApps;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\App\Resources\IntegrationApps\Pages\ManageIntegrationApps as ManageOwnApps;
use App\Models\Connection;
use App\Models\IntegrationApp;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config([
        'services.asana.client_id' => null, 'services.asana.client_secret' => null,
        'services.atlassian.client_id' => null, 'services.atlassian.client_secret' => null,
        'services.google.client_id' => null, 'services.google.client_secret' => null,
        'services.github_app' => ['id' => null, 'slug' => null, 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null],
    ]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->other = Tenant::factory()->create(['slug' => 'other']);
});

function asanaApp(array $attributes = []): IntegrationApp
{
    return IntegrationApp::query()->create($attributes + ['type' => 'asana', 'name' => 'Asana', 'credentials' => ['client_id' => 'asana-one', 'client_secret' => 'secret-one']]);
}

it('gives a platform app only to the tenants it is for, and a tenant\'s own to that tenant alone', function () {
    $platform = asanaApp();

    // For none yet.
    expect(Integrations::available('asana', $this->tenant))->toBe([]);

    $platform->tenants()->attach($this->tenant);

    expect(array_keys(Integrations::available('asana', $this->tenant)))->toEqual([$platform->id])
        ->and(Integrations::available('asana', $this->other))->toBe([]);

    $own = asanaApp(['tenant_id' => $this->other->id, 'name' => 'Our Asana', 'credentials' => ['client_id' => 'asana-two', 'client_secret' => 'secret-two']]);
    $platform->update(['for_all_tenants' => true]);

    expect(array_keys(Integrations::available('asana', $this->other)))->toEqual([$platform->id, $own->id])
        ->and(array_keys(Integrations::available('asana', $this->tenant)))->toEqual([$platform->id]);

    // What .env sets is there for every tenant, first.
    config(['services.asana.client_id' => 'env-app', 'services.asana.client_secret' => 'env-secret']);

    expect(array_key_first(Integrations::available('asana', $this->tenant)))->toBe('env');
});

it('lets an administrator add apps, one of a single type, and keeps a secret left empty on a change', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ManageIntegrationApps::class)
        ->callAction('create', data: ['type' => 'google', 'name' => 'Google', 'credentials' => ['client_id' => 'google-app', 'client_secret' => 'google-secret']])
        ->assertHasNoFormErrors();

    $google = IntegrationApp::query()->where('type', 'google')->sole();

    expect($google->for_all_tenants)->toBeTrue()
        ->and($google->credential('client_secret'))->toBe('google-secret')
        // There is one to sign in with.
        ->and(IntegrationAppResource::types())->not->toHaveKey('google')->toHaveKey('asana');

    // Signing in with Google reads it, where .env sets none.
    Integrations::apply();

    expect(config('services.google.client_id'))->toBe('google-app');

    livewire(ManageIntegrationApps::class)
        ->mountAction(TestAction::make('edit')->table($google))
        ->assertSchemaStateSet(['credentials.client_id' => 'google-app', 'credentials.client_secret' => null])
        ->setActionData(['credentials' => ['client_id' => 'google-app-2', 'client_secret' => '']])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($google->refresh()->credential('client_id'))->toBe('google-app-2')
        ->and($google->credential('client_secret'))->toBe('google-secret');

    livewire(ManageIntegrationApps::class)
        ->callAction('create', data: ['type' => 'asana', 'name' => 'Agency Asana', 'credentials' => ['client_id' => 'a', 'client_secret' => 'b'], 'for_all_tenants' => false, 'tenants' => [$this->tenant->id]])
        ->assertHasNoFormErrors();

    expect(IntegrationApp::query()->where('name', 'Agency Asana')->sole()->tenants->modelKeys())->toBe([$this->tenant->id]);
});

it('lets a tenant add its own app, for itself alone', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setTenant($this->tenant);

    livewire(ManageOwnApps::class)
        ->callAction('create', data: ['type' => 'atlassian', 'name' => 'Our Atlassian', 'credentials' => ['client_id' => 'ours', 'client_secret' => 'our-secret']])
        ->assertHasNoFormErrors();

    $app = IntegrationApp::query()->sole();

    expect($app->tenant_id)->toBe($this->tenant->id)
        ->and($app->for_all_tenants)->toBeFalse()
        ->and(Integrations::available('atlassian', $this->tenant))->toHaveKey((string) $app->id)
        ->and(Integrations::available('atlassian', $this->other))->toBe([]);
});

it('signs a connection in through the app picked, and renews it through that one', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setTenant($this->tenant);
    $first = asanaApp(['for_all_tenants' => true]);
    $second = asanaApp(['tenant_id' => $this->tenant->id, 'name' => 'Our Asana', 'credentials' => ['client_id' => 'asana-two', 'client_secret' => 'secret-two']]);
    $asana = Connection::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'tickets', 'driver' => 'asana', 'name' => 'Asana', 'credentials' => [], 'settings' => ['workspace' => '1200']]);

    // Two to pick from: Connect asks which.
    livewire(ManageConnections::class)
        ->mountAction(TestAction::make('connect')->table($asana))
        ->assertSchemaStateSet(['app' => (string) $first->id]);

    $location = $this->get("/connections/{$asana->id}/oauth?app={$second->id}")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $asked);

    expect($asked['client_id'])->toBe('asana-two');

    Http::fake(['app.asana.com/-/oauth_token' => Http::sequence()
        ->push(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'expires_in' => 1])
        ->push(['access_token' => 'access-two', 'refresh_token' => 'refresh-two', 'expires_in' => 3600])]);

    $this->get('/connections/oauth/callback?code=code-one&state='.$asked['state'])->assertRedirect();

    expect($asana->refresh()->setting('oauth_app'))->toBe((string) $second->id);

    $this->travel(5)->seconds();

    expect(app(OAuthTokens::class)->token($asana->refresh()))->toBe('access-two');
    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token' && $request['client_id'] === 'asana-two');

    // An app that is not this tenant's is refused.
    $elsewhere = asanaApp(['tenant_id' => $this->other->id, 'name' => 'Theirs']);
    $this->get("/connections/{$asana->id}/oauth?app={$elsewhere->id}")->assertNotFound();
});

it('says why a connection cannot sign in, and offers the GitHub App only to the tenants it is for', function () {
    $asana = app(Drivers::class)->get(ConnectionKind::Tickets, 'asana');

    expect(Integrations::unavailable($asana, 'Asana', $this->tenant))->toContain('not set up');

    asanaApp()->tenants()->attach($this->other);

    expect(Integrations::unavailable($asana, 'Asana', $this->tenant))->toContain('not on for this team');

    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setTenant($this->tenant);
    $github = IntegrationApp::query()->create(['type' => 'github_app', 'name' => 'GitHub App', 'credentials' => [
        'app_id' => '1', 'slug' => 'review', 'client_id' => 'Iv1', 'client_secret' => 'cs', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nabc", 'webhook_secret' => 'hook',
    ]]);
    Integrations::apply();

    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->not->toHaveKey('github-app');

    $github->tenants()->attach($this->tenant);

    expect(app(Drivers::class)->options(ConnectionKind::SourceControl))->toHaveKey('github-app');
});

it('takes the GitHub App .env sets as the one, and offers no second', function () {
    config(['services.github_app' => ['id' => '9', 'slug' => 'env-app', 'client_id' => 'Iv1.env', 'client_secret' => 'cs-env', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nenv", 'webhook_secret' => 'hook-env']]);
    $listed = IntegrationApp::query()->create(['type' => 'github_app', 'name' => 'Listed', 'for_all_tenants' => true, 'credentials' => [
        'app_id' => '2', 'slug' => 'listed', 'client_id' => 'Iv1.listed', 'client_secret' => 'cs2', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\ndef", 'webhook_secret' => 'hook2',
    ]]);

    expect(array_keys(Integrations::available('github_app', $this->tenant)))->toBe(['env'])
        ->and(IntegrationAppResource::types())->not->toHaveKey('github_app');

    // Of a type there can be several of, .env's is one among them.
    config(['services.asana.client_id' => 'env-app', 'services.asana.client_secret' => 'env-secret']);
    asanaApp(['for_all_tenants' => true]);

    expect(Integrations::available('asana', $this->tenant))->toHaveCount(2);
});

it('names the installation\'s apps on a tenant\'s Apps page, .env\'s among them', function () {
    config(['services.asana.client_id' => 'env-app', 'services.asana.client_secret' => 'env-secret']);
    asanaApp(['tenant_id' => $this->tenant->id, 'name' => 'Our Asana']);
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setTenant($this->tenant);

    livewire(ManageOwnApps::class)
        ->assertSee('From .env (Asana)')
        ->assertDontSee('Our Asana (Asana)');
});
