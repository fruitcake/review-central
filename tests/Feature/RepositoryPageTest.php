<?php

use App\Filament\App\Resources\Repositories\Pages\ViewRepository;
use App\Filament\RunPresenter;
use App\Models\OpenFinding;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRule;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\RepositoryOverview;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->other = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'slug' => 'blog']);
    $this->member = User::factory()->create();
    $this->member->tenants()->attach($this->repository->tenant);

    $this->run = fn (Repository $repository, int $pullRequest, string $title, array $attributes = []): ReviewRun => ReviewRun::query()->create($attributes + [
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => $repository->slug,
        'branch' => 'feature/x', 'commit' => str_repeat('a', 40), 'pull_request_id' => $pullRequest, 'status' => ReviewRun::STATUS_FINISHED,
        'result' => 'FAILED', 'agent' => 'claude-code', 'cost' => 0.5, 'duration_seconds' => 120, 'started_at' => now()->subDay(),
        'findings' => [['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'category' => 'security', 'title' => $title]],
    ]);
});

it('shows one repository\'s reviews, open pull requests, open findings and rules, and nothing of another', function () {
    ($this->run)($this->repository, 12, 'Export leaks invoices');
    ($this->run)($this->other, 7, 'Blog leaks drafts');
    OpenPullRequest::query()->create(['repository_id' => $this->repository->id, 'pull_request_id' => 12])->forceFill(['title' => 'Add the export', 'author' => 'Mira', 'opened_at' => now()->subDays(2)])->save();
    OpenPullRequest::query()->create(['repository_id' => $this->other->id, 'pull_request_id' => 7])->forceFill(['title' => 'Add drafts'])->save();
    OpenFinding::query()->create(['tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 9, 'finding_id' => 'F2', 'severity' => 'MEDIUM', 'title' => 'Cache never expires', 'path' => 'app/Cache.php']);
    OpenFinding::query()->create(['tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 8, 'finding_id' => 'F3', 'title' => 'Closed one', 'status' => OpenFinding::DISMISSED]);
    ReviewRule::factory()->create(['tenant_id' => $this->repository->tenant_id, 'title' => 'Every controller action checks the policy']);
    ReviewRule::factory()->create(['tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->other->id, 'title' => 'Only for the blog']);

    $this->actingAs($this->member);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    livewire(ViewRepository::class, ['record' => $this->repository->id])
        ->assertOk()
        ->assertSee('Add the export')
        ->assertSee('Changes requested')
        ->assertSee('Cache never expires')
        ->assertSee('Every controller action checks the policy')
        ->assertSee('Claude Code')
        ->assertDontSee('Add drafts')
        ->assertDontSee('Closed one')
        ->assertDontSee('Only for the blog')
        // A member reads it; the rules and settings are the admins'.
        ->assertActionHidden('addRule');
});

it('opens from the list of repositories, and not for another team\'s', function () {
    $elsewhere = Repository::factory()->create();
    $this->actingAs($this->member);
    $tenant = $this->repository->tenant->slug;

    $this->get("/app/{$tenant}/repositories/{$this->repository->id}")->assertOk()->assertSee('shop');
    $this->get("/app/{$tenant}/repositories/{$elsewhere->id}")->assertNotFound();
});

it('gives the median duration of an even number of reviews as the middle of the two, a review of no time included', function () {
    ($this->run)($this->repository, 12, 'One', ['duration_seconds' => 60]);
    ($this->run)($this->repository, 13, 'Two', ['duration_seconds' => 540]);

    $figures = collect(app(RepositoryOverview::class)->forRepository($this->repository, 30)['figures'])->keyBy('label');

    expect($figures['Median duration']['value'])->toBe(RunPresenter::duration(300));

    ($this->run)($this->repository, 14, 'Three', ['duration_seconds' => 0]);
    ($this->run)($this->repository, 15, 'Four', ['duration_seconds' => 0]);

    $reviewers = app(RepositoryOverview::class)->forRepository($this->repository, 30)['reviewers'];

    expect($reviewers[0]['median'])->toBe(RunPresenter::duration(30));
});
