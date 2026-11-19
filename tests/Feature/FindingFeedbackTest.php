<?php

use App\Enums\FindingVerdictKind;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Models\FindingVerdict;
use App\Models\Repository;
use App\Models\ReviewRating;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\FindingFeedback;
use App\Services\TenantOverview;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create();
    $this->tenant = $this->repository->tenant;
    $this->user = User::factory()->create(['name' => 'Jens']);
    $this->user->tenants()->attach($this->tenant);

    $this->run = ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => str_repeat('a', 40), 'status' => ReviewRun::STATUS_FINISHED, 'result' => 'FAILED', 'started_at' => now()->subHour(),
        'findings' => [
            ['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'category' => 'security', 'title' => 'Unescaped output'],
            ['id' => 'F2', 'severity' => 'LOW', 'status' => 'new', 'category' => 'tests', 'title' => 'No test'],
        ],
    ]);
});

it('keeps one verdict of a user on a finding, replaces it, and takes it back when given twice', function () {
    $feedback = app(FindingFeedback::class);

    expect($feedback->give($this->run, $this->user, 'F1', FindingVerdictKind::Valid))->toBe(FindingVerdictKind::Valid)
        ->and($feedback->give($this->run, $this->user, 'F1', FindingVerdictKind::Wrong))->toBe(FindingVerdictKind::Wrong)
        ->and($feedback->of($this->run, $this->user))->toBe(['F1' => FindingVerdictKind::Wrong])
        ->and(FindingVerdict::query()->count())->toBe(1)
        ->and($feedback->give($this->run, $this->user, 'F1', FindingVerdictKind::Wrong))->toBeNull()
        ->and(FindingVerdict::query()->count())->toBe(0);

    expect(fn () => $feedback->give($this->run, $this->user, 'F9', FindingVerdictKind::Valid))
        ->toThrow(InvalidArgumentException::class, 'This review has no finding F9.');
});

it('counts a verdict as accepted only when the finding was valid, next to what the rating page gathered', function () {
    $other = User::factory()->create();
    $feedback = app(FindingFeedback::class);

    $feedback->give($this->run, $this->user, 'F1', FindingVerdictKind::Valid);
    $feedback->give($this->run, $other, 'F1', FindingVerdictKind::NotAProblem);
    $feedback->give($this->run, $this->user, 'F2', FindingVerdictKind::Wrong);
    ReviewRating::query()->create(['review_run_id' => $this->run->id, 'score' => 4, 'findings' => ['F2' => true]]);

    $overview = app(TenantOverview::class)->for($this->tenant, 30);
    $categories = collect($overview['categories'])->keyBy('name');

    expect(collect($overview['figures'])->firstWhere('label', 'Findings accepted'))->toMatchArray(['value' => '50%', 'note' => '2 of 4 rated findings'])
        ->and($categories['Security'])->toMatchArray(['rated' => 2, 'accepted' => 1])
        ->and($categories['Tests'])->toMatchArray(['rated' => 2, 'accepted' => 1]);
});

it('takes a verdict and a rating from the review\'s own page', function () {
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    livewire(ViewReviewRun::class, ['record' => $this->run->uuid])
        ->assertSee('Unescaped output')
        ->assertSee('Valid, will fix')
        ->call('judge', 'F1', 'valid')
        ->call('judge', 'F2', 'not-a-verdict')
        ->call('judge', 'F9', 'valid')
        ->call('rate', 4)
        ->call('rate', 5)
        ->call('rate', 9);

    expect(FindingVerdict::query()->get(['finding_id', 'verdict', 'user_id'])->toArray())->toBe([
        ['finding_id' => 'F1', 'verdict' => 'valid', 'user_id' => $this->user->id],
    ]);

    $rating = ReviewRating::query()->sole();

    expect($rating->only(['score', 'name', 'user_id']))->toBe(['score' => 5, 'name' => 'Jens', 'user_id' => $this->user->id]);
});
