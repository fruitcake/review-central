<?php

use App\Filament\Admin\Pages\Settings;
use App\Filament\Auth\EditProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformSettings;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

it('opens the profile as a page of the tenant, linked from the user menu', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);

    $this->actingAs($user)
        ->get('/app/acme/profile')
        ->assertOk()
        ->assertSee([url('mcp/user'), url('mcp/agent')]);
    $this->get('/app/acme')->assertSee(url('app/acme/profile'));
});

it('links the platform admin to their profile in the first tenant', function () {
    Tenant::factory()->create(['name' => 'Zeta', 'slug' => 'zeta']);
    Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')
        ->assertSee(url('app/acme/profile'))
        ->assertDontSee(url('app/zeta/profile'));
});

it('keeps the profile for a user in no tenant, and the admin of an installation without any', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee(url('app/profile'));
    $this->get('/app/profile')->assertOk();

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')
        ->assertSee(url('app/profile'));
});

it('changes the email only once the new address has opened the link mailed to it', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'attacker@example.com', 'password' => 'secret-password']);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(EditProfile::class)
        ->fillForm(['email' => 'new-hire@acme.test', 'currentPassword' => 'secret-password'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->email)->toBe('attacker@example.com');
    $link = null;
    Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification, array $channels, object $notifiable) use (&$link): bool {
        $link = $notification->url;

        return $notifiable->routes['mail'] === 'new-hire@acme.test';
    });

    $this->get($link);

    expect($user->fresh()->email)->toBe('new-hire@acme.test');
});

it('tells people the name to give the MCP server: the one the administrator set, else the app name as a slug', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);
    $settings = app(PlatformSettings::class);

    $this->actingAs($user)->get('/app/acme/profile')
        ->assertSee('claude mcp add --transport http review-central '.url('mcp/agent'));

    $settings->save([PlatformSettings::APP_NAME => 'Acme Review']);
    $this->get('/app/acme/profile')->assertSee('claude mcp add --transport http acme-review '.url('mcp/agent'));

    $settings->save([PlatformSettings::MCP_NAME => 'acme-review-central']);
    $this->get('/app/acme/profile')
        ->assertSee('claude mcp add --transport http acme-review-central '.url('mcp/agent'))
        ->assertSee('named acme-review-central');

    // The administrator sets it, in a form clients accept.
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(Settings::class)
        ->fillForm([PlatformSettings::MCP_NAME => 'Acme Review!'])
        ->call('save')
        ->assertHasFormErrors([PlatformSettings::MCP_NAME]);
});
