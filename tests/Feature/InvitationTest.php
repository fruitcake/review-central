<?php

use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Invitation;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

use function Pest\Livewire\livewire;

function actAsAdministrator(): User
{
    $admin = User::factory()->create(['name' => 'Ada Admin', 'is_admin' => true]);

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('adds a user without a password and mails them an invitation', function () {
    Notification::fake();
    actAsAdministrator();

    livewire(ManageUsers::class)
        ->callAction('create', ['name' => 'Nora New', 'email' => 'nora@example.com'])
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'nora@example.com')->sole();

    expect($user->password)->not->toBeEmpty();

    Notification::assertSentTo($user, Invitation::class, fn (Invitation $invitation): bool => $invitation->invitedBy === 'Ada Admin');
});

it('sends the invitation again from the list', function () {
    Notification::fake();
    actAsAdministrator();
    $user = User::factory()->create();

    livewire(ManageUsers::class)->callAction(TestAction::make('invite')->table($user));

    Notification::assertSentTo($user, Invitation::class);
});

it('mails a password reset from the list, for the panel the user signs in to', function (bool $isAdmin, string $path) {
    Notification::fake();
    actAsAdministrator();
    $user = User::factory()->create(['is_admin' => $isAdmin]);

    livewire(ManageUsers::class)->callAction(TestAction::make('passwordReset')->table($user));

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($path): bool {
        return str_contains($notification->url, $path) && URL::hasValidSignature(Request::create($notification->url));
    });
})->with([
    'a user' => [false, '/app/password-reset/reset'],
    'an administrator' => [true, '/admin/password-reset/reset'],
]);

it('mails the invitation in the look of the panels', function () {
    $user = User::factory()->create(['name' => 'Nora New']);

    $html = (string) (new Invitation('Ada Admin'))->toMail($user)->render();

    expect($html)
        ->toContain('Hello Nora New,')
        ->toContain('Ada Admin has made an account for you')
        ->toContain('Choose a password')
        ->toContain('/invitation/'.$user->id)
        // The deep teal band and the teal of the brand mark.
        ->toContain('background-color: #042f2e')
        ->toContain('background-color: #0f766e');
});

it('mails someone invited to one tenant in that tenant\'s colours', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme', 'theme_colors' => ['ink' => '#161e36', 'accent' => '#c8d35b']]);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);

    $mail = (new Invitation('Ada Admin'))->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('You have been invited to Acme on Review Central')
        ->and($html)->toContain('background-color: #161e36')
        ->and($html)->toContain('background-color: #c8d35b')
        ->and($html)->not->toContain('background-color: #042f2e');
});

it('leads an invitation to the page where the password is chosen, on the panel the user belongs to', function (bool $isAdmin, string $path) {
    $user = User::factory()->create(['is_admin' => $isAdmin]);

    $this->get(Invitation::url($user))->assertRedirectContains($path);
})->with([
    'a user' => [false, '/app/password-reset/reset'],
    'an administrator' => [true, '/admin/password-reset/reset'],
]);

it('lets the invited user choose a password, which ends the invitation', function () {
    $user = User::factory()->create();
    $invitation = Invitation::url($user);

    $reset = $this->get($invitation)->headers->get('Location');
    parse_str((string) parse_url($reset, PHP_URL_QUERY), $query);

    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(ResetPassword::class, ['email' => $query['email'], 'token' => $query['token']])
        ->fillForm(['password' => 'a-brand-new-password', 'passwordConfirmation' => 'a-brand-new-password'])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(Hash::check('a-brand-new-password', $user->refresh()->password))->toBeTrue();

    $this->get($invitation)->assertRedirect('/app/login');
});

it('ends an invitation when the address it was sent to is changed', function () {
    $user = User::factory()->create(['email' => 'nroa@example.com']);
    $invitation = Invitation::url($user);

    $user->update(['email' => 'nora@example.com']);

    $this->get($invitation)->assertRedirect('/app/login');
    $this->get(Invitation::url($user))->assertRedirectContains('/app/password-reset/reset');
});

it('refuses an invitation that was tampered with or has expired', function () {
    $user = User::factory()->create();
    $invitation = Invitation::url($user);

    $this->get($invitation.'x')->assertForbidden();

    $this->travel(Invitation::VALID_DAYS + 1)->days();

    $this->get($invitation)->assertForbidden();
});

it('offers a forgotten password on both sign-in pages', function (string $panel) {
    $this->get("/{$panel}/login")->assertOk()->assertSee("/{$panel}/password-reset/request");
})->with(['app', 'admin']);

it('links an administrator from the tenant panel to the admin panel, and nobody else', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);

    $this->actingAs($user)->get('/app/acme')->assertOk()->assertDontSee('Platform admin');

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/app/acme')->assertOk()->assertSee('Platform admin')->assertSee(url('/admin'));
});
