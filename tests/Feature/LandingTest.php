<?php

use App\Enums\LandingPage;
use App\Filament\Auth\EditProfile;
use App\Filament\TenantRole;
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

it('sends a member to what waits for them in the first of their tenants, and an admin of it to its overview', function () {
    $user = User::factory()->create();
    $user->tenants()->attach(Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']));
    $user->tenants()->attach(Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']));
    Tenant::factory()->create(['name' => 'Aardvark', 'slug' => 'not-theirs']);

    $this->actingAs($user)->get('/')->assertRedirect('/app/acme/my-work');

    $user->tenants()->updateExistingPivot(Tenant::query()->where('slug', 'acme')->sole(), ['role' => TenantRole::ADMIN]);

    $this->get('/')->assertRedirect('/app/acme');
});

it('lands an administrator where they chose, in the tenant they chose', function (?string $page, string $url) {
    Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $zeta = Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    $this->actingAs(User::factory()->create(['is_admin' => true, 'landing_page' => $page, 'landing_tenant_id' => $zeta->id]));

    $this->get('/')->assertRedirect($url);
})->with([
    'nothing chosen' => [null, '/admin'],
    'the platform' => ['platform', '/admin'],
    'a tenant' => ['tenant', '/app/zeta'],
    'their own' => ['personal', '/app/zeta/my-work'],
]);

it('lets an admin of a tenant choose to land on what waits for them, in one of their own tenants only', function () {
    $acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $zeta = Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    $notTheirs = Tenant::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $user = User::factory()->create();
    $user->tenants()->attach($acme, ['role' => TenantRole::ADMIN]);
    $user->tenants()->attach($zeta);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($acme);

    livewire(EditProfile::class)
        ->assertFormFieldExists('landing_page')
        ->fillForm(['landing_page' => 'platform'])
        ->call('save')
        ->assertHasFormErrors(['landing_page']);

    livewire(EditProfile::class)
        ->fillForm(['landing_page' => 'personal', 'landing_tenant_id' => $notTheirs->id])
        ->call('save')
        ->assertHasFormErrors(['landing_tenant_id']);

    livewire(EditProfile::class)
        ->fillForm(['landing_page' => 'personal', 'landing_tenant_id' => $zeta->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh())->landing_page->toBe(LandingPage::Personal)->landing_tenant_id->toBe($zeta->id);
    $this->get('/')->assertRedirect('/app/zeta/my-work');
});

it('leaves a member nothing to choose, and what they chose as an admin, or in a tenant they left, no say', function () {
    $acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $gone = Tenant::factory()->create(['name' => 'Gone', 'slug' => 'gone']);
    $user = User::factory()->create(['landing_page' => 'tenant', 'landing_tenant_id' => $gone->id]);
    $user->tenants()->attach($acme);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($acme);

    livewire(EditProfile::class)->assertFormFieldDoesNotExist('landing_page');
    $this->get('/')->assertRedirect('/app/acme/my-work');
});

it('lands an admin made a member since in the first of their tenants, not the one they chose', function () {
    $acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $zeta = Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    $user = User::factory()->create(['landing_page' => 'personal', 'landing_tenant_id' => $zeta->id]);
    $user->tenants()->attach($acme);
    $user->tenants()->attach($zeta, ['role' => TenantRole::ADMIN]);
    $this->actingAs($user);

    $this->get('/')->assertRedirect('/app/zeta/my-work');

    $user->tenants()->updateExistingPivot($zeta, ['role' => TenantRole::MEMBER]);

    $this->get('/')->assertRedirect('/app/acme/my-work');
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
