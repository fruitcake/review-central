<?php

use App\Filament\App\Pages\MyWork;
use App\Filament\App\Resources\OpenFindings\Pages\ListOpenFindings;
use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Filament\App\Resources\Repositories\Pages\ManageRepositories;
use App\Filament\App\Resources\ReviewRules\Pages\ManageReviewRules;
use App\Filament\TenantRole;
use App\Models\Connection;
use App\Models\OpenFinding;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRule;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Http::fake();

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->authored = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'shop']);
    $this->reviewed = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'api']);
    $this->others = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'website']);

    $this->pullRequest = fn (Repository $repository, int $id, array $attributes = []): OpenPullRequest => OpenPullRequest::query()->forceCreate($attributes + [
        'repository_id' => $repository->id, 'pull_request_id' => $id, 'title' => "Change {$id}", 'author' => 'Nora', 'author_nickname' => 'nora', 'branch' => "feature/{$id}",
    ]);

    // Merged a while ago: the repository is still theirs.
    ($this->pullRequest)($this->authored, 1, ['author' => 'Sam', 'author_nickname' => 'sam', 'closed_as' => OpenPullRequest::MERGED, 'closed_at' => now()->subDays(20)]);
    $this->toReview = ($this->pullRequest)($this->reviewed, 2, ['reviewers' => [['name' => 'Sam', 'account_id' => null, 'nickname' => 'sam', 'state' => null, 'at' => null]]]);
    $this->notTheirs = ($this->pullRequest)($this->others, 3);
    $this->theirs = ($this->pullRequest)($this->authored, 4, ['author' => 'Sam', 'author_nickname' => 'sam', 'reviewers' => [['name' => 'Nora', 'account_id' => null, 'nickname' => 'nora', 'state' => null, 'at' => null]]]);

    $this->member = User::factory()->create(['name' => 'Sam Smith', 'source_accounts' => ['sam']]);
    $this->member->tenants()->attach($this->tenant);

    $this->openTenant = function (User $user): void {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
    };
});

it('shows a member what waits for them, from the repositories they opened or reviewed a pull request of', function () {
    ($this->openTenant)($this->member);

    livewire(MyWork::class)
        ->assertOk()
        // Theirs to review.
        ->assertSee('Change 2')
        // Theirs, waiting for Nora.
        ->assertSee('Change 4')
        ->assertSee('shop')
        ->assertSee('api')
        ->assertDontSee('Change 3')
        ->assertDontSee('website');
});

it('opens a member\'s lists on their repositories, and an admin\'s on the whole team\'s', function () {
    ($this->openTenant)($this->member);

    livewire(ListPullRequests::class)
        ->assertSet('activeTab', ListPullRequests::MY_REPOSITORIES)
        ->assertCanSeeTableRecords([$this->toReview, $this->theirs])
        ->assertCanNotSeeTableRecords([$this->notTheirs]);

    livewire(ManageRepositories::class)
        ->assertCanSeeTableRecords([$this->authored, $this->reviewed])
        ->assertCanNotSeeTableRecords([$this->others]);

    $admin = User::factory()->create(['source_accounts' => ['sam']]);
    $admin->tenants()->attach($this->tenant, ['role' => TenantRole::ADMIN]);
    ($this->openTenant)($admin);

    livewire(ListPullRequests::class)
        ->assertSet('activeTab', 'active')
        ->assertCanSeeTableRecords([$this->notTheirs]);
});

it('opens a member who has no pull requests here on everything', function () {
    $newcomer = User::factory()->create(['source_accounts' => ['someone-new']]);
    $newcomer->tenants()->attach($this->tenant);
    ($this->openTenant)($newcomer);

    livewire(ListPullRequests::class)
        ->assertSet('activeTab', 'active')
        ->assertCanSeeTableRecords([$this->notTheirs]);
});

it('keeps the rules for every repository and the open findings of their own under the member\'s tab', function () {
    $everywhere = ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => null]);
    $own = ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => $this->authored->id]);
    $elsewhere = ReviewRule::factory()->create(['tenant_id' => $this->tenant->id, 'repository_id' => $this->others->id]);

    $finding = fn (Repository $repository, string $id): OpenFinding => OpenFinding::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $repository->id, 'pull_request_id' => 1, 'finding_id' => $id, 'title' => "Finding {$id}", 'status' => OpenFinding::PRESENT,
    ]);
    $ownFinding = $finding($this->reviewed, 'F1');
    $otherFinding = $finding($this->others, 'F2');

    ($this->openTenant)($this->member);

    livewire(ManageReviewRules::class)
        ->assertSet('activeTab', ManageReviewRules::MY_REPOSITORIES)
        ->assertCanSeeTableRecords([$everywhere, $own])
        ->assertCanNotSeeTableRecords([$elsewhere]);

    livewire(ListOpenFindings::class)
        ->assertCanSeeTableRecords([$ownFinding])
        ->assertCanNotSeeTableRecords([$otherFinding]);
});
