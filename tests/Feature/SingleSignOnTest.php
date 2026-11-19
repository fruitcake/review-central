<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    config(['services.google.client_id' => 'client-one', 'services.google.client_secret' => 'secret-one']);

    /**
     * Comes back from Google as this account.
     *
     * @param  array<string, mixed>  $claims
     */
    $this->signIn = function (string $email, array $claims = [], string $id = 'google-1') {
        $account = (new GoogleUser)->setRaw($claims + ['email_verified' => true])->map(['id' => $id, 'name' => 'Nora New', 'email' => $email]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($account);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $this->get('/sign-in/google/callback');
    };
});

it('shows the way in only where the installation is set up for it', function () {
    $this->get('/app/login')->assertOk()->assertSee('Sign in with Google');

    config(['services.google.client_secret' => null]);

    $this->get('/app/login')->assertOk()->assertDontSee('Sign in with Google');
    $this->get('/sign-in/google')->assertNotFound();
    $this->get('/sign-in/github')->assertNotFound();
});

it('signs someone in to the account their verified address has, and knows them by Google\'s id afterwards', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email' => 'Nora@Example.com']);
    $user->tenants()->attach($tenant);

    ($this->signIn)('nora@example.com', ['hd' => 'example.com'])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(UserIdentity::query()->sole()->only(['user_id', 'provider', 'provider_id']))->toBe(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-1']);

    // Their address at Google changes; they are still who they were.
    auth()->logout();
    ($this->signIn)('nora.new@example.com', ['hd' => 'example.com'])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('makes an account for someone whose organisation a tenant lets in, and for nobody else', function () {
    $tenant = Tenant::factory()->create(['sign_in_domains' => ['example.org']]);
    $other = Tenant::factory()->create(['sign_in_domains' => ['example.com']]);

    ($this->signIn)('nora@example.org', ['hd' => 'example.org'])->assertRedirect(route('home'));

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->only(['name', 'email']))->toBe(['name' => 'Nora New', 'email' => 'nora@example.org'])
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->tenants()->pluck('tenants.id')->all())->toBe([$tenant->id]);
});

it('lets nobody in who has no account and no organisation a tenant takes', function (string $email, array $claims) {
    Tenant::factory()->create(['sign_in_domains' => ['example.org']]);

    ($this->signIn)($email, $claims)->assertRedirect('/app/login')->assertSessionHas('sign_in_refused');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)->and(UserIdentity::query()->count())->toBe(0);
})->with([
    'another organisation' => ['nora@example.com', ['hd' => 'example.com']],
    // A personal Google account can be made under any address; Google names no organisation for it.
    'a personal account at the domain' => ['nora@example.org', []],
    'an organisation that is not the address\'s' => ['nora@gmail.com', ['hd' => 'example.org']],
    'an address Google did not verify' => ['nora@example.org', ['hd' => 'example.org', 'email_verified' => false]],
]);

it('says on the sign-in page why someone was not let in', function () {
    ($this->signIn)('nora@example.com');

    $this->get('/app/login')->assertOk()->assertSee('There is no account for nora@example.com.');
});

it('goes back to the sign-in page when Google sends nobody', function () {
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('access_denied'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/sign-in/google/callback')->assertRedirect('/app/login')->assertSessionHas('sign_in_refused', 'Signing in did not complete. Try again.');
    $this->assertGuest();
});

it('takes Google\'s word for an address only where Google delivers its mail', function (string $email, array $claims, bool $signedIn) {
    $user = User::factory()->create(['email' => $email]);

    $response = ($this->signIn)($email, $claims);

    if ($signedIn) {
        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        return;
    }

    // Verified once, when the account was made: someone who had the address then may not have it now.
    $response->assertRedirect('/app/login')->assertSessionHas('sign_in_refused');
    $this->assertGuest();
    expect(UserIdentity::query()->count())->toBe(0);
})->with([
    'a Gmail address' => ['nora@gmail.com', [], true],
    'an account the organisation manages' => ['nora@example.com', ['hd' => 'example.com'], true],
    'a personal account under a company address' => ['nora@example.com', [], false],
    'an organisation that is not the address\'s' => ['nora@example.com', ['hd' => 'other.example'], false],
]);

it('tells someone who signs in with a second Google account under one address, instead of failing', function () {
    $user = User::factory()->create(['email' => 'nora@example.com']);
    UserIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-1']);

    ($this->signIn)('nora@example.com', ['hd' => 'example.com'], 'google-2')
        ->assertRedirect('/app/login')
        ->assertSessionHas('sign_in_refused', 'The account for nora@example.com is already linked to another account there. Sign in with that one, or with your password.');

    $this->assertGuest();
    expect(UserIdentity::query()->count())->toBe(1);
});

it('adds someone who already had an account to a tenant that takes their organisation', function () {
    $own = Tenant::factory()->create();
    $listed = Tenant::factory()->create(['sign_in_domains' => ['example.org']]);
    $user = User::factory()->create(['email' => 'nora@example.org']);
    $user->tenants()->attach($own);

    ($this->signIn)('nora@example.org', ['hd' => 'example.org'])->assertRedirect(route('home'));

    // Into the one that lists the domain, and out of none.
    expect($user->tenants()->orderBy('tenants.id')->pluck('tenants.id')->all())->toBe([$own->id, $listed->id]);
});
