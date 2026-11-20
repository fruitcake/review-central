<?php

use App\Connections\OAuthTokens;
use App\Exceptions\ConnectionFailed;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Tickets\AsanaDriver;
use App\Tickets\JiraDriver;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config([
        'services.asana.client_id' => 'asana-app', 'services.asana.client_secret' => 'asana-secret',
        'services.atlassian.client_id' => 'atlassian-app', 'services.atlassian.client_secret' => 'atlassian-secret',
    ]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant, ['role' => 'admin']);
    $this->actingAs($this->user);

    $this->connection = fn (string $driver, array $settings): Connection => Connection::query()->create([
        'tenant_id' => $this->tenant->id, 'kind' => 'tickets', 'driver' => $driver, 'name' => ucfirst($driver), 'credentials' => [], 'settings' => $settings,
    ]);
});

it('sends someone to Asana to say yes, and keeps what comes back for the connection', function () {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);
    Http::fake(['app.asana.com/-/oauth_token' => Http::response(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'expires_in' => 3600])]);

    $location = $this->get("/connections/{$asana->id}/oauth")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $asked);

    expect($location)->toStartWith('https://app.asana.com/-/oauth_authorize?')
        ->and($asked['client_id'])->toBe('asana-app')
        ->and($asked['response_type'])->toBe('code')
        ->and($asked['redirect_uri'])->toBe(url('/connections/oauth/callback'))
        ->and($asked['scope'])->toContain('tasks:read', 'stories:read')
        ->and($asked['state'])->toHaveLength(40);

    $this->get('/connections/oauth/callback?code=code-one&state='.$asked['state'])->assertRedirect('/app/acme/connections');

    // The secret goes from this server, in the form Asana takes.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.asana.com/-/oauth_token' && $request->isForm()
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'code-one' && $request['client_secret'] === 'asana-secret'
        && $request['redirect_uri'] === url('/connections/oauth/callback'));

    $asana->refresh();

    expect($asana->credential('oauth_access_token'))->toBe('access-one')
        ->and($asana->credential('oauth_refresh_token'))->toBe('refresh-one')
        ->and((int) $asana->credential('oauth_expires_at'))->toBeGreaterThan(time() + 3000);
});

it('takes no answer it did not ask for', function (string $query) {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);
    Http::fake();

    $this->get("/connections/{$asana->id}/oauth")->assertRedirect();
    $this->get("/connections/oauth/callback?{$query}")->assertRedirect('/app/acme/connections');

    expect(OAuthTokens::has($asana->refresh()))->toBeFalse();
    Http::assertNothingSent();

    // And the question is spent: the same answer a second time finds nothing to answer.
    $this->get("/connections/oauth/callback?{$query}")->assertNotFound();
})->with([
    'another state' => ['code=code-one&state=someone-elses'],
    'a refusal' => ['error=access_denied'],
]);

it('lets only someone of the tenant connect its connection, and only a service that is set up for it', function () {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);

    $this->actingAs(User::factory()->create());
    $this->get("/connections/{$asana->id}/oauth")->assertNotFound();

    $this->actingAs($this->user);
    config(['services.asana.client_secret' => null]);
    $this->get("/connections/{$asana->id}/oauth")->assertNotFound();
});

it('reads Asana as the account that signed in, and renews its access when that ran out', function () {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);
    app(OAuthTokens::class)->store($asana, ['access_token' => 'access-old', 'refresh_token' => 'refresh-one', 'expires_in' => 30]);

    Http::fake([
        'app.asana.com/-/oauth_token' => Http::response(['access_token' => 'access-new', 'expires_in' => 3600]),
        'app.asana.com/api/1.0/workspaces/1200' => Http::response(['data' => ['gid' => '1200']]),
    ]);

    app(AsanaDriver::class)->test($asana);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.asana.com/-/oauth_token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-one');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/workspaces/1200') && $request->hasHeader('Authorization', 'Bearer access-new'));

    // Asana sent no new refresh token, so the one it has still holds.
    expect($asana->refresh()->credential('oauth_refresh_token'))->toBe('refresh-one');

    // Still good for an hour: nothing is renewed for the next request.
    app(AsanaDriver::class)->test($asana);
    Http::assertSentCount(3);
});

it('says the connection has to be connected again when the service takes its refresh token no more', function () {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);
    app(OAuthTokens::class)->store($asana, ['access_token' => 'access-old', 'refresh_token' => 'refresh-gone', 'expires_in' => 30]);
    Http::fake(['app.asana.com/-/oauth_token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(AsanaDriver::class)->test($asana))->toThrow(ConnectionFailed::class, 'connect again');
});

it('connects Jira for the site the connection names, and reaches it through Atlassian by that site\'s id', function () {
    $jira = ($this->connection)('jira', ['site' => 'https://acme.atlassian.net/']);
    Http::fake([
        'auth.atlassian.com/oauth/token' => Http::sequence()
            ->push(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'expires_in' => 3600])
            ->push(['access_token' => 'access-two', 'refresh_token' => 'refresh-two', 'expires_in' => 3600]),
        'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
            ['id' => 'cloud-other', 'url' => 'https://other.atlassian.net'],
            ['id' => 'cloud-acme', 'url' => 'https://acme.atlassian.net'],
        ]),
        'api.atlassian.com/ex/jira/cloud-acme/rest/api/2/myself' => Http::response(['accountId' => '1']),
    ]);

    $location = $this->get("/connections/{$jira->id}/oauth")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $asked);

    expect($location)->toStartWith('https://auth.atlassian.com/authorize?')
        ->and($asked['audience'])->toBe('api.atlassian.com')
        ->and($asked['scope'])->toBe('read:jira-work read:jira-user offline_access')
        ->and($asked['prompt'])->toBe('consent');

    $this->get('/connections/oauth/callback?code=code-one&state='.$asked['state'])->assertRedirect('/app/acme/connections');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.atlassian.com/oauth/token' && $request->isJson() && $request['client_secret'] === 'atlassian-secret');

    $jira->refresh();
    expect($jira->setting('cloud_id'))->toBe('cloud-acme');

    app(JiraDriver::class)->test($jira);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.atlassian.com/ex/jira/cloud-acme/rest/api/2/myself' && $request->hasHeader('Authorization', 'Bearer access-one'));

    // Atlassian hands out a new refresh token each time and retires the old one.
    $this->travel(2)->hours();
    app(JiraDriver::class)->test($jira);

    expect($jira->refresh()->credential('oauth_refresh_token'))->toBe('refresh-two');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/myself') && $request->hasHeader('Authorization', 'Bearer access-two'));
});

it('does not connect Jira when the account that signed in has no access to the connection\'s site', function () {
    $jira = ($this->connection)('jira', ['site' => 'acme.atlassian.net']);
    Http::fake([
        'auth.atlassian.com/oauth/token' => Http::response(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'expires_in' => 3600]),
        'api.atlassian.com/oauth/token/accessible-resources' => Http::response([['id' => 'cloud-other', 'url' => 'https://other.atlassian.net']]),
    ]);

    $location = $this->get("/connections/{$jira->id}/oauth")->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $asked);
    $this->get('/connections/oauth/callback?code=code-one&state='.$asked['state'])->assertRedirect('/app/acme/connections');

    expect(OAuthTokens::has($jira->refresh()))->toBeFalse();
});

it('offers to connect in the list, and asks for no token, where the installation is registered with the service', function () {
    $asana = ($this->connection)('asana', ['workspace' => '1200']);
    Filament::setTenant($this->tenant);

    livewire(ManageConnections::class)->assertActionVisible(TestAction::make('connect')->table($asana));

    config(['services.asana.client_id' => null]);

    livewire(ManageConnections::class)->assertActionHidden(TestAction::make('connect')->table($asana));
});
