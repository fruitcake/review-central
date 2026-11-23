<?php

use App\Filament\Auth\EditProfile;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

it('opens the profile, which belongs to no tenant', function () {
    $this->actingAs(User::factory()->create())
        ->get(Filament::getPanel('app')->getProfileUrl())
        ->assertOk();
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
