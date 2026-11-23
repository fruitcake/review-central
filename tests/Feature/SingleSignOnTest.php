<?php

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\SignInCode;
use App\Services\PersonalConnections;
use App\Services\SignInCodes;
use App\Services\SingleSignOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\BitbucketProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as BitbucketUser;
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

/**
 * Comes back from Bitbucket as this account, with this primary confirmed address
 * (null for none), through the one callback its consumer has.
 */
function signInWithBitbucket(?string $email, string $id = '{nora-uuid}'): TestResponse
{
    config(['services.bitbucket.client_id' => 'consumer-key', 'services.bitbucket.client_secret' => 'consumer-secret']);
    test()->bitbucketAccount = (new BitbucketUser)->setRaw(['uuid' => $id])->map(['id' => $id, 'nickname' => 'nora-b', 'name' => 'Nora New', 'email' => $email]);

    // Mockery keeps the first expectation, so the one mock answers with the account of the moment.
    if (! isset(test()->bitbucketProvider)) {
        test()->bitbucketProvider = Mockery::mock(BitbucketProvider::class);
        test()->bitbucketProvider->shouldReceive('redirectUrl')->andReturnSelf();
        test()->bitbucketProvider->shouldReceive('setScopes')->andReturnSelf();
        test()->bitbucketProvider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://bitbucket.org/site/oauth2/authorize'));
        test()->bitbucketProvider->shouldReceive('user')->andReturnUsing(fn (): BitbucketUser => test()->bitbucketAccount);
        Socialite::shouldReceive('driver')->with('bitbucket')->andReturn(test()->bitbucketProvider);
    }

    test()->get('/sign-in/bitbucket')->assertRedirect('https://bitbucket.org/site/oauth2/authorize');
    test()->get('/connections/bitbucket/callback?code=abc&state=xyz')->assertRedirect('/sign-in/bitbucket/callback?code=abc&state=xyz');

    return test()->get('/sign-in/bitbucket/callback?code=abc&state=xyz');
}

/**
 * The code the last mail to this address carried.
 */
function mailedCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(SignInCode::class, function (SignInCode $notification, array $channels, object $notifiable) use ($email, &$code): bool {
        $code = $notification->code;

        return $notifiable->routes['mail'] === $email;
    });

    return (string) $code;
}

it('signs a known Bitbucket account in at once, and mails a code the first time, which links it', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'nora@example.com']);

    $this->get('/app/login')->assertDontSee('Sign in with Bitbucket');
    signInWithBitbucket('nora@example.com')->assertRedirect(route('sign-in.code'));

    $this->assertGuest();
    $this->get('/app/login')->assertSee('Sign in with Bitbucket');
    $this->get(route('sign-in.code'))->assertOk()->assertSee('We sent a code to nora@example.com');

    // A wrong code links nothing; the right one, typed in this browser, does.
    $this->post(route('sign-in.code.check'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'That is not the code we sent.']);
    $this->assertGuest();

    $this->post(route('sign-in.code.check'), ['code' => mailedCode('nora@example.com')])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(UserIdentity::query()->sole()->only(['user_id', 'provider', 'provider_id']))->toBe(['user_id' => $user->id, 'provider' => 'bitbucket', 'provider_id' => '{nora-uuid}']);

    // From now on, without a code.
    auth()->logout();
    Notification::fake();
    signInWithBitbucket('nora@example.com')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    Notification::assertNothingSent();
});

it('makes an account for a Bitbucket address a tenant lets in once its code is typed, and for nobody else', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create(['sign_in_domains' => ['example.org']]);

    signInWithBitbucket('nora@example.com')->assertRedirect('/app/login')->assertSessionHas('sign_in_refused', 'There is no account for nora@example.com. Ask for an invitation, or ask your organisation\'s administrator to let its people sign in.');
    signInWithBitbucket(null)->assertRedirect('/app/login')->assertSessionHas('sign_in_refused');

    Notification::assertNothingSent();

    signInWithBitbucket('Nora@Example.org')->assertRedirect(route('sign-in.code'));

    expect(User::query()->count())->toBe(0);

    $this->post(route('sign-in.code.check'), ['code' => mailedCode('nora@example.org')])->assertRedirect(route('home'));

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->only(['name', 'email']))->toBe(['name' => 'Nora New', 'email' => 'nora@example.org'])
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->tenants()->pluck('tenants.id')->all())->toBe([$tenant->id]);
});

it('takes a code only a few times, only for a while, and only once', function () {
    Notification::fake();
    User::factory()->create(['email' => 'nora@example.com']);

    signInWithBitbucket('nora@example.com');
    $code = mailedCode('nora@example.com');

    foreach (range(1, SignInCodes::ATTEMPTS) as $attempt) {
        $this->post(route('sign-in.code.check'), ['code' => '000000']);
    }

    $this->post(route('sign-in.code.check'), ['code' => $code])->assertRedirect('/app/login')->assertSessionHas('sign_in_refused', 'That code is no longer valid. Sign in again for a new one.');
    $this->assertGuest();

    Notification::fake();
    signInWithBitbucket('nora@example.com');
    $code = mailedCode('nora@example.com');
    $this->travel(SignInCodes::MINUTES + 1)->minutes();

    $this->post(route('sign-in.code.check'), ['code' => $code])->assertRedirect('/app/login');
    $this->assertGuest();
    expect(UserIdentity::query()->count())->toBe(0);
});

it('refuses a Bitbucket account for an address whose account has another one linked', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'nora@example.com']);
    UserIdentity::query()->create(['user_id' => $user->id, 'provider' => 'bitbucket', 'provider_id' => '{other-uuid}']);

    signInWithBitbucket('nora@example.com')->assertRedirect('/app/login')->assertSessionHas('sign_in_refused', 'The account for nora@example.com is already linked to another account there. Sign in with that one, or with your password.');

    Notification::assertNothingSent();
});

it('signs a Bitbucket account in no more once it is disconnected', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'nora@example.com']);
    UserIdentity::query()->create(['user_id' => $user->id, 'provider' => 'bitbucket', 'provider_id' => '{nora-uuid}']);

    app(PersonalConnections::class)->disconnect($user);

    signInWithBitbucket('nora@example.com')->assertRedirect(route('sign-in.code'));
    $this->assertGuest();
});

it('links no account by a code when the user linked another one meanwhile', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'nora@example.com']);

    signInWithBitbucket('nora@example.com', '{account-a}')->assertRedirect(route('sign-in.code'));
    $code = mailedCode('nora@example.com');

    // From the profile, in another browser.
    app(SingleSignOn::class)->link($user, 'bitbucket', '{account-b}');

    $this->post(route('sign-in.code.check'), ['code' => $code])->assertRedirect('/app/login')->assertSessionHas('sign_in_refused');

    $this->assertGuest();
    expect(UserIdentity::query()->sole()->provider_id)->toBe('{account-b}');
});
