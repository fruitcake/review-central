<?php

use App\Filament\Auth\EditProfile;
use App\Models\PersonalConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\User as BitbucketUser;

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

    expect(PersonalConnection::query()->count())->toBe(0);

    config(['services.bitbucket.client_id' => null]);

    livewire(EditProfile::class)->assertDontSee('Your Bitbucket account');
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
