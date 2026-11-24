<?php

use App\Filament\Auth\EditProfile;
use App\Models\Connection;
use App\Models\PersonalConnection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\PersonalConnections;
use App\SourceControl\ReadOnlyClient;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as BitbucketUser;
use Laravel\Socialite\Two\User as GitHubUser;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['services.bitbucket.client_id' => 'consumer-key', 'services.bitbucket.client_secret' => 'consumer-secret']);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->user = User::factory()->create(['source_accounts' => ['sam-gh']]);
    $this->user->tenants()->attach($this->tenant);

    /**
     * Comes back from Bitbucket as this account, granted these scopes.
     */
    Http::fake(['api.bitbucket.org/2.0/user*' => fn () => Http::response(['uuid' => '{sam-uuid}'], 200, ['X-OAuth-Scopes' => $this->scopes])]);

    $this->connect = function (string $scopes = 'pullrequest, account') {
        $this->scopes = $scopes;
        $account = (new BitbucketUser)->setRaw(['account_id' => '557058:sam', 'uuid' => '{sam-uuid}'])
            ->map(['id' => '{sam-uuid}', 'nickname' => 'sam-b', 'name' => 'Sam Sample'])
            ->setToken('sam-token')->setRefreshToken('sam-refresh')->setExpiresIn(7200);
        $provider = Mockery::mock(BitbucketProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('setScopes')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://bitbucket.org/site/oauth2/authorize'));
        $provider->shouldReceive('user')->andReturn($account);
        Socialite::shouldReceive('driver')->with('bitbucket')->andReturn($provider);

        return $this->get('/connections/bitbucket/callback');
    };
});

it('keeps the Bitbucket account a user connects, for them alone, and names it among their accounts', function () {
    $this->actingAs($this->user);

    ($this->connect)()->assertRedirect(url('app/acme/profile'));

    expect(PersonalConnection::query()->sole()->only(['user_id', 'host', 'account_id', 'nickname', 'access_token', 'refresh_token', 'scopes']))->toBe([
        'user_id' => $this->user->id, 'host' => 'bitbucket', 'account_id' => '557058:sam', 'nickname' => 'sam-b',
        'access_token' => 'sam-token', 'refresh_token' => 'sam-refresh', 'scopes' => ['pullrequest', 'account'],
    ])
        ->and($this->user->refresh()->source_accounts)->toBe(['sam-gh', '557058:sam', 'sam-b'])
        ->and(PersonalConnection::query()->sole()->getRawOriginal('access_token'))->not->toContain('sam-token');

    // Connecting again replaces it.
    ($this->connect)('account');

    expect(PersonalConnection::query()->sole()->scopes)->toBe(['account'])
        ->and($this->user->refresh()->source_accounts)->toBe(['sam-gh', '557058:sam', 'sam-b']);
});

it('goes back to the profile in the tenant Connect was clicked in, where the user is in it', function () {
    $this->tenant->update(['name' => 'Acme']);
    $zeta = Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    $this->user->tenants()->attach($zeta);
    Tenant::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->actingAs($this->user);

    ($this->connect)();
    $this->get('/connections/bitbucket?tenant=zeta')->assertRedirect('https://bitbucket.org/site/oauth2/authorize');
    $this->get('/connections/bitbucket/callback')->assertRedirect(url('app/zeta/profile'));

    // Not in that one: the profile in their first tenant.
    $this->get('/connections/bitbucket?tenant=other');
    $this->get('/connections/bitbucket/callback')->assertRedirect(url('app/acme/profile'));
});

it('connects only for someone signed in, and only where the installation is registered at Bitbucket', function () {
    $this->get('/connections/bitbucket')->assertRedirect(route('filament.app.auth.login'));
    $this->get('/connections/bitbucket/callback')->assertRedirect(route('filament.app.auth.login'));

    config(['services.bitbucket.client_secret' => null]);
    $this->actingAs($this->user);

    $this->get('/connections/bitbucket')->assertNotFound();
    expect(PersonalConnection::query()->count())->toBe(0);
});

it('shows the account on the profile, says when it cannot write, and lets the user disconnect it', function () {
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);

    livewire(EditProfile::class)->assertSee('Not connected.');

    ($this->connect)('account');

    livewire(EditProfile::class)->assertSee('connected without the right to comment on pull requests');

    // A consumer that also lets it write: commenting is part of that.
    ($this->connect)('pullrequest:write, account');

    livewire(EditProfile::class)->assertSee('Connected as sam-b.');

    ($this->connect)();

    livewire(EditProfile::class)
        ->assertSee('Connected as sam-b.')
        ->callAction(TestAction::make('disconnectBitbucket')->schemaComponent('bitbucket_actions'))
        ->assertNotified('Bitbucket is disconnected');

    expect(PersonalConnection::query()->count())->toBe(0)
        ->and(UserIdentity::query()->count())->toBe(0);

    // Linked by signing in only: it says so, and can be undone too.
    UserIdentity::query()->create(['user_id' => $this->user->id, 'provider' => 'bitbucket', 'provider_id' => '{sam-uuid}']);

    livewire(EditProfile::class)
        ->assertSee('It signs you in, but is not connected to reply as you.')
        ->callAction(TestAction::make('disconnectBitbucket')->schemaComponent('bitbucket_actions'));

    expect(UserIdentity::query()->count())->toBe(0);

    config(['services.bitbucket.client_id' => null]);

    livewire(EditProfile::class)->assertDontSee('Your Bitbucket account');
});

it('connects nothing for a session from before the password changed', function () {
    $this->actingAs($this->user)->withSession(['password_hash_web' => $this->user->getAuthPassword()]);
    $this->user->forceFill(['password' => 'changed-since'])->save();

    $this->get('/connections/bitbucket')->assertRedirect(route('filament.app.auth.login'));
    $this->assertGuest();
});

it('keeps the account the user connected while an older one was being renewed', function () {
    $connection = PersonalConnection::query()->create([
        'user_id' => $this->user->id, 'host' => 'bitbucket', 'account_id' => 'id-a', 'access_token' => 'a-token', 'refresh_token' => 'a-refresh',
        'expires_at' => now()->subMinute(), 'scopes' => ['account', 'pullrequest'],
    ]);

    // Bitbucket answers for account A after the user connected account B.
    Http::fake(['bitbucket.org/site/oauth2/access_token' => function () use ($connection) {
        PersonalConnection::query()->whereKey($connection->id)->first()->update(['account_id' => 'id-b', 'access_token' => 'b-token', 'refresh_token' => 'b-refresh', 'expires_at' => now()->addHour()]);

        return Http::response(['access_token' => 'a-token-2', 'refresh_token' => 'a-refresh-2', 'expires_in' => 7200]);
    }]);

    expect(app(PersonalConnections::class)->problem($this->user, 'bitbucket'))->toBeNull()
        ->and($connection->refresh()->only(['account_id', 'access_token', 'refresh_token']))->toBe(['account_id' => 'id-b', 'access_token' => 'b-token', 'refresh_token' => 'b-refresh']);
});

it('signs the user in with the account they connect from then on, and connects none that is someone else\'s', function () {
    $other = User::factory()->create();
    UserIdentity::query()->create(['user_id' => $other->id, 'provider' => 'bitbucket', 'provider_id' => '{sam-uuid}']);
    $this->actingAs($this->user);

    ($this->connect)()->assertRedirect(url('app/acme/profile'));

    expect(PersonalConnection::query()->count())->toBe(0)
        ->and(UserIdentity::query()->sole()->user_id)->toBe($other->id);

    $other->delete();
    ($this->connect)();

    expect(UserIdentity::query()->sole()->only(['user_id', 'provider', 'provider_id']))->toBe(['user_id' => $this->user->id, 'provider' => 'bitbucket', 'provider_id' => '{sam-uuid}'])
        ->and(PersonalConnection::query()->sole()->user_id)->toBe($this->user->id);
});

it('links no account for a session the password changed under while at Bitbucket', function () {
    $this->actingAs($this->user)->withSession(['password_hash_web' => $this->user->getAuthPassword()]);
    $this->user->forceFill(['password' => 'reset-meanwhile'])->save();

    ($this->connect)()->assertRedirect(route('filament.app.auth.login'));

    $this->assertGuest();
    expect(PersonalConnection::query()->count())->toBe(0)
        ->and(UserIdentity::query()->count())->toBe(0);
});

describe('on GitHub, through the GitHub App', function () {
    beforeEach(function () {
        config(['services.github_app' => ['id' => '4242', 'slug' => 'review-central', 'client_id' => 'Iv1.client', 'client_secret' => 'client-secret', 'private_key' => 'a key', 'webhook_secret' => null]]);

        /**
         * Comes back from GitHub as this account, with a token that lasts eight hours.
         */
        $this->connectGitHub = function () {
            $account = (new GitHubUser)->setRaw(['id' => 5150, 'login' => 'sam-gh'])
                ->map(['id' => 5150, 'nickname' => 'sam-gh', 'name' => 'Sam Sample'])
                ->setToken('ghu-token')->setRefreshToken('ghr-refresh')->setExpiresIn(28800);
            $provider = Mockery::mock(GithubProvider::class);
            $provider->shouldReceive('redirectUrl')->andReturnSelf();
            $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://github.com/login/oauth/authorize'));
            $provider->shouldReceive('user')->andReturn($account);
            Socialite::shouldReceive('buildProvider')->with(GithubProvider::class, Mockery::on(fn (array $config): bool => $config['client_id'] === 'Iv1.client' && $config['redirect'] === url('/connections/github/callback')))->andReturn($provider);

            return $this->get('/connections/github/callback');
        };

        $this->githubRepository = fn (string $driver) => Repository::factory()
            ->for(Connection::factory()->state(['tenant_id' => $this->tenant->id, 'driver' => $driver, 'settings' => ['workspace' => 'acme', 'installation_id' => '77']]), 'integration')
            ->create(['tenant_id' => $this->tenant->id, 'workspace' => 'acme', 'slug' => 'shop']);
    });

    it('keeps the GitHub account a user connects, which signs them in, and forgets it at GitHub too on disconnecting', function () {
        Http::fake(['api.github.com/applications/*' => Http::response(null, 204)]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);

        // To the GitHub App, which comes back on the callback for people.
        $this->get('/connections/github?tenant=acme')
            ->assertRedirectContains('https://github.com/login/oauth/authorize?client_id=Iv1.client&redirect_uri='.urlencode(url('/connections/github/callback')));
        ($this->connectGitHub)()->assertRedirect(url('app/acme/profile'));

        expect(PersonalConnection::query()->sole()->only(['host', 'account_id', 'nickname', 'access_token', 'refresh_token', 'scopes']))->toBe([
            'host' => 'github', 'account_id' => '5150', 'nickname' => 'sam-gh', 'access_token' => 'ghu-token', 'refresh_token' => 'ghr-refresh', 'scopes' => [],
        ])
            ->and(UserIdentity::query()->sole()->only(['provider', 'provider_id']))->toBe(['provider' => 'github', 'provider_id' => '5150'])
            ->and($this->user->refresh()->source_accounts)->toBe(['sam-gh', '5150']);

        livewire(EditProfile::class)
            ->assertSee('Your GitHub account')
            ->assertSee('Connected as sam-gh.')
            ->callAction(TestAction::make('disconnectGithub')->schemaComponent('github_actions'))
            ->assertNotified('GitHub is disconnected');

        expect(PersonalConnection::query()->count())->toBe(0)
            ->and(UserIdentity::query()->count())->toBe(0);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/applications/Iv1.client/token'
            && $request['access_token'] === 'ghu-token');

        // Without the GitHub App, there is nothing to connect.
        config(['services.github_app.private_key' => null]);

        livewire(EditProfile::class)->assertDontSee('Your GitHub account');
        $this->get('/connections/github')->assertNotFound();
    });

    it('writes as the user only on repositories reviewed through the GitHub App, renewing the token GitHub spends once', function () {
        $connection = PersonalConnection::query()->create([
            'user_id' => $this->user->id, 'host' => 'github', 'account_id' => '5150', 'nickname' => 'sam-gh',
            'access_token' => 'ghu-old', 'refresh_token' => 'ghr-old', 'expires_at' => now()->subMinute(), 'scopes' => [],
        ]);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu-new', 'refresh_token' => 'ghr-new', 'expires_in' => 28800]),
            'api.github.com/repos/acme/shop/issues/12/comments' => Http::response(['id' => 99]),
        ]);
        $connections = app(PersonalConnections::class);

        // A repository GitHub is reached on with a token of the tenant's own: the app is not there.
        expect($connections->writerFor($this->user, ($this->githubRepository)('github')))->toBeNull();

        // Nor on one whose connection is set to post nothing.
        $quiet = ($this->githubRepository)('github-app');
        $quiet->integration->forceFill(['settings' => [...$quiet->integration->settings, ReadOnlyClient::SETTING => ReadOnlyClient::READ]])->save();

        expect($connections->writerFor($this->user, $quiet))->toBeNull();

        $connections->writerFor($this->user, ($this->githubRepository)('github-app'))->addPullRequestComment('12', 'Not so.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'ghr-old' && $request['client_id'] === 'Iv1.client');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/12/comments') && $request->hasHeader('Authorization', 'Bearer ghu-new'));
        expect($connection->refresh()->only(['access_token', 'refresh_token']))->toBe(['access_token' => 'ghu-new', 'refresh_token' => 'ghr-new']);
    });

    it('uses the token another request renewed first, as GitHub takes a refresh token once, and says when GitHub renews none', function () {
        $connection = PersonalConnection::query()->create([
            'user_id' => $this->user->id, 'host' => 'github', 'account_id' => '5150',
            'access_token' => 'ghu-old', 'refresh_token' => 'ghr-old', 'expires_at' => now()->subMinute(), 'scopes' => [],
        ]);

        // GitHub refuses with 200 and an error; meanwhile the other request kept its new token.
        Http::fake(['github.com/login/oauth/access_token' => function () use ($connection) {
            PersonalConnection::query()->whereKey($connection->id)->update(['access_token' => encrypt('ghu-other', false), 'refresh_token' => encrypt('ghr-other', false), 'expires_at' => now()->addHours(8)]);

            return Http::response(['error' => 'bad_refresh_token']);
        }]);

        expect(app(PersonalConnections::class)->problem($this->user, 'github'))->toBeNull();

        $connection->refresh()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_refresh_token'])]);

        expect(app(PersonalConnections::class)->problem($this->user, 'github'))->toBe('GitHub no longer accepts your connection: connect your account again on your profile.');
    });
});
