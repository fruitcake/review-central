<?php

use App\Models\Tenant;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('asks a visitor to sign in', function () {
    $this->get('/')->assertRedirect('/app/login');
});

it('sends an administrator to the admin panel, also from the tenant panel\'s own address', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/')->assertRedirect('/admin');
    $this->get('/app')->assertRedirect('/');
});

it('sends a user to the first of their tenants', function () {
    $user = User::factory()->create();
    $user->tenants()->attach(Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']));
    $user->tenants()->attach(Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']));
    Tenant::factory()->create(['name' => 'Aardvark', 'slug' => 'not-theirs']);

    $this->actingAs($user)->get('/')->assertRedirect('/app/acme');
});

it('tells a user without a tenant so, instead of answering 404', function () {
    $user = User::factory()->create(['email' => 'new@example.com']);

    $this->actingAs($user)->get('/app')->assertRedirect('/');

    $this->get('/')
        ->assertOk()
        ->assertSee('No tenant yet')
        ->assertSee('new@example.com')
        ->assertSee('/app/logout');
});

it('lands a sign-in at home, and at the page that asked for it when there was one', function () {
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'a-long-password']);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $signIn = fn () => livewire(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'a-long-password'])
        ->call('authenticate');

    $signIn()->assertRedirect('/');

    auth()->logout();
    session()->put('url.intended', url('/app/acme/review-runs'));

    $signIn()->assertRedirect('/app/acme/review-runs');
});

it('carries the brand mark as its icon, on the sign-in page and on the pages outside the panels', function () {
    $this->get('/app/login')->assertOk()->assertSee('favicon.svg');

    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('favicon.svg');

    expect(file_get_contents(public_path('favicon.svg')))->toContain('#0f766e');
});
