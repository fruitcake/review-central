<?php

use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\App\Pages\Members;
use App\Filament\App\Pages\Overview;
use App\Filament\App\Resources\Repositories\Pages\ManageRepositories;
use App\Filament\TenantRole;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Invitation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->member = User::factory()->create(['name' => 'Mira']);
    $this->member->tenants()->attach($this->tenant);
    $this->admin = User::factory()->create(['name' => 'Ada']);
    $this->admin->tenants()->attach($this->tenant, ['role' => TenantRole::ADMIN]);
});

it('lets a member read and start reviews, and keeps the keys and settings to the admins', function (string $path, bool $member) {
    $this->actingAs($this->member);
    $member ? $this->get("/app/acme/{$path}")->assertOk() : $this->get("/app/acme/{$path}")->assertForbidden();

    $this->actingAs($this->admin);
    $this->get("/app/acme/{$path}")->assertOk();
})->with([
    ['review-runs', true],
    ['pull-requests', true],
    ['repositories', true],
    ['review-questions', true],
    ['source-control', false],
    ['ai-providers', false],
    ['tickets', false],
    ['integration-apps', false],
    ['review-instructions', false],
    ['review-settings', false],
    ['members', false],
]);

it('treats an administrator of the platform as an admin of every tenant, and nobody else as one of another', function () {
    $platform = User::factory()->create(['is_admin' => true]);
    $elsewhere = Tenant::factory()->create();

    expect(TenantRole::isAdmin($platform, $this->tenant))->toBeTrue()
        ->and(TenantRole::isAdmin($this->admin, $this->tenant))->toBeTrue()
        ->and(TenantRole::isAdmin($this->member, $this->tenant))->toBeFalse()
        ->and(TenantRole::isAdmin($this->admin, $elsewhere))->toBeFalse();
});

it('lets an admin invite people, change their role and take them out, keeping one admin', function () {
    Notification::fake();
    $this->actingAs($this->admin);
    Filament::setTenant($this->tenant);
    $known = User::factory()->create(['email' => 'known@example.com']);

    livewire(Members::class)
        ->callAction(TestAction::make('invite')->table(), data: ['email' => 'New@Example.com', 'name' => 'Nina', 'role' => TenantRole::ADMIN])
        ->callAction(TestAction::make('invite')->table(), data: ['email' => 'known@example.com', 'role' => TenantRole::MEMBER]);

    $new = User::query()->where('email', 'new@example.com')->sole();

    expect(TenantRole::isAdmin($new, $this->tenant))->toBeTrue()
        ->and($this->tenant->users()->whereKey($known->id)->wherePivot('role', TenantRole::MEMBER)->exists())->toBeTrue();
    // Only someone new is mailed a way in.
    Notification::assertSentTo($new, Invitation::class);
    Notification::assertNotSentTo($known, Invitation::class);

    livewire(Members::class)->callTableAction('role', $this->member, data: ['role' => TenantRole::ADMIN]);

    expect(TenantRole::isAdmin($this->member->refresh(), $this->tenant))->toBeTrue();

    livewire(Members::class)->callTableAction('remove', $known)->assertNotified($known->name.' is removed from the team');

    expect($this->tenant->users()->whereKey($known->id)->exists())->toBeFalse();

    // Down to one admin: that one stays.
    $this->tenant->users()->updateExistingPivot($this->member->id, ['role' => TenantRole::MEMBER]);
    $this->tenant->users()->updateExistingPivot($new->id, ['role' => TenantRole::MEMBER]);
    livewire(Members::class)->callTableAction('role', $this->admin, data: ['role' => TenantRole::MEMBER]);
    livewire(Members::class)->callTableAction('remove', $this->admin);

    expect(TenantRole::isAdmin($this->admin->refresh(), $this->tenant))->toBeTrue();
});

it('lets the platform\'s administrator say who a tenant\'s admins are', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ManageTenants::class)
        ->mountAction(TestAction::make('edit')->table($this->tenant))
        ->assertSchemaStateSet(['admins' => [$this->admin->id]])
        ->setActionData(['admins' => [$this->member->id]])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(TenantRole::isAdmin($this->member->refresh(), $this->tenant))->toBeTrue()
        ->and(TenantRole::isAdmin($this->admin->refresh(), $this->tenant))->toBeFalse();
});

it('refuses a member what would change the team\'s keys or repositories', function () {
    $repository = Repository::factory()->create(['tenant_id' => $this->tenant->id]);
    $asana = Connection::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'tickets', 'driver' => 'asana', 'name' => 'Asana', 'credentials' => [], 'settings' => ['workspace' => '1200']]);
    config(['services.asana.client_id' => 'asana-app', 'services.asana.client_secret' => 'asana-secret']);
    $this->actingAs($this->member);
    Filament::setTenant($this->tenant);

    // Signing in would put the member's own tokens on the team's connection.
    $this->get("/connections/{$asana->id}/oauth")->assertForbidden();

    livewire(ManageRepositories::class)
        ->assertTableActionHidden('delete', $repository);

    expect($repository->fresh())->not->toBeNull();

    // The overview sends them nowhere they are refused.
    livewire(Overview::class)
        ->assertActionHidden('addRepository')
        ->assertSee('An admin of this team sets this up.')
        ->assertDontSee('Add an AI provider');

    // An admin may do all of it.
    $this->actingAs($this->admin);
    $this->get("/connections/{$asana->id}/oauth")->assertRedirect();
    livewire(ManageRepositories::class)
        ->assertTableActionVisible('delete', $repository);
});
