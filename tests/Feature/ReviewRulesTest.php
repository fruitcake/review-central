<?php

use App\Enums\FindingVerdictKind;
use App\Enums\ReviewRuleKind;
use App\Filament\App\Resources\ReviewRules\Pages\ManageReviewRules;
use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Filament\TenantRole;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRule;
use App\Models\ReviewRun;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->tenant = $this->repository->tenant;
    $this->admin = User::factory()->create();
    $this->admin->tenants()->attach($this->tenant, ['role' => TenantRole::ADMIN]);
    $this->member = User::factory()->create();
    $this->member->tenants()->attach($this->tenant);

    $this->as = function (User $user): void {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
    };
});

it('lets an admin write a rule for one repository or for all, and a member only read them', function () {
    // Made before a team is current: one made while it is would be made the current team's.
    $elsewhere = ReviewRule::factory()->create(['title' => 'Another team\'s rule']);
    ($this->as)($this->admin);

    livewire(ManageReviewRules::class)
        ->callAction(CreateAction::class, [
            'kind' => ReviewRuleKind::Check->value,
            'title' => 'Every controller action checks the policy',
            'repository_id' => $this->repository->id,
            'paths' => ['app/Http/**'],
            'severity' => 'HIGH',
            'enabled' => true,
        ])
        ->assertHasNoActionErrors();

    $rule = ReviewRule::query()->where('tenant_id', $this->tenant->id)->sole();

    expect($rule->only(['tenant_id', 'repository_id', 'kind', 'paths', 'severity']))
        ->toBe(['tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'kind' => ReviewRuleKind::Check, 'paths' => ['app/Http/**'], 'severity' => 'HIGH']);

    ($this->as)($this->member);

    livewire(ManageReviewRules::class)
        ->assertCanSeeTableRecords([$rule])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->assertActionHidden(CreateAction::class);
});

it('lets an admin make a rule of a finding anyone in the team judged not to be one, for its repository and file', function () {
    ($this->as)($this->admin);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
        'findings' => [['id' => 'F1', 'severity' => 'MEDIUM', 'status' => 'new', 'title' => 'Raw query in a migration', 'path' => 'database/migrations/2026_add.php', 'line' => 12]],
    ]);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])->assertDontSee('Make it a rule');

    // Offered to an admin once anyone in the team judged the finding not to be one.
    ($this->as)($this->member);
    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->call('judge', 'F1', FindingVerdictKind::NotAProblem->value)
        // A member judges; making a rule is the admins'.
        ->assertDontSee('Make it a rule');
    ($this->as)($this->admin);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])
        ->assertSee('Make it a rule')
        ->mountAction('makeRule', ['finding' => 'F1'])
        ->assertSchemaStateSet([
            'kind' => ReviewRuleKind::Convention->value,
            'title' => 'Raw query in a migration',
            'repository_id' => $this->repository->id,
            'paths' => ['database/migrations/2026_add.php'],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(ReviewRule::query()->sole()->only(['kind', 'repository_id', 'source']))
        ->toBe(['kind' => ReviewRuleKind::Convention, 'repository_id' => $this->repository->id, 'source' => "{$run->uuid}:F1"]);
});

it('names each repository so that two of the same name cannot be mixed up, in the form and the list, and keeps a removed one\'s rules its own', function () {
    $partner = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'workspace' => 'partner', 'slug' => 'shop']);
    $twin = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Partner GitHub'])->id, 'workspace' => 'partner', 'slug' => 'shop']);
    $sameName = Repository::factory()->create(['connection_id' => $this->repository->connection_id, 'workspace' => 'partner', 'slug' => 'shop']);
    // Two connections named alike: then only the connection's number tells them apart.
    $alike = Repository::factory()->create(['connection_id' => Connection::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'GitHub'])->id, 'workspace' => 'acme', 'slug' => 'shop']);
    $this->repository->integration->update(['name' => 'GitHub']);
    $rule = ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => $partner->id, 'title' => 'Partner rule']);
    $partner->delete();
    ($this->as)($this->admin);

    $options = ReviewRuleResource::repositoryOptions();

    expect($options[$this->repository->id])->toBe("acme/shop (GitHub #{$this->repository->connection_id})")
        ->and($options[$alike->id])->toBe("acme/shop (GitHub #{$alike->connection_id})")
        ->and($options[$sameName->id])->toBe('partner/shop (GitHub)')
        // The same workspace and name on another connection says which.
        ->and($options[$twin->id])->toBe('partner/shop (Partner GitHub)')
        ->and($options)->not->toHaveKey($partner->id);

    livewire(ManageReviewRules::class)
        ->assertCanSeeTableRecords([$rule])
        ->assertSee('partner/shop (removed)');

    ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => $alike->id, 'title' => 'On the second GitHub']);

    // The list names the repository as the form does.
    livewire(ManageReviewRules::class)->assertSee("acme/shop (GitHub #{$alike->connection_id})");
});
