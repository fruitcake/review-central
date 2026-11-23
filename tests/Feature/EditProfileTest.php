<?php

use App\Filament\Auth\EditProfile;
use App\Models\Tenant;
use App\Models\User;
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

it('links the platform admin to their profile in a tenant', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')
        ->assertSee(url('app/acme/profile'));
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
