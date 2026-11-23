<?php

use App\Enums\FindingVerdictKind;
use App\Enums\FindingVerdictReason;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Models\FindingVerdict;
use App\Models\Repository;
use App\Models\ReviewRating;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\FindingFeedback;
use App\Services\TenantOverview;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create();
    $this->tenant = $this->repository->tenant;
    $this->user = User::factory()->create(['name' => 'Sam Sample']);
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

    expect($rating->only(['score', 'name', 'user_id']))->toBe(['score' => 5, 'name' => 'Sam Sample', 'user_id' => $this->user->id]);
});

it('takes why a finding was dismissed on the review\'s own page, and drops it when the verdict changes', function () {
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    $page = livewire(ViewReviewRun::class, ['record' => $this->run->uuid])
        ->call('judge', 'F1', 'valid')
        ->call('explain', 'F1', 'nitpick')
        ->call('judge', 'F2', 'not_a_problem')
        ->assertSee('Why?')
        ->call('explain', 'F2', 'out_of_scope')
        // The team reads why, on the finding.
        ->assertSee('Sam Sample: Not a problem · Out of scope');

    // A finding judged valid takes no reason.
    expect(FindingVerdict::query()->pluck('reason', 'finding_id')->all())->toBe(['F1' => null, 'F2' => FindingVerdictReason::OutOfScope]);

    $page->call('judge', 'F2', 'wrong');

    expect(FindingVerdict::query()->where('finding_id', 'F2')->sole()->reason)->toBeNull();
});

it('shows how the period\'s findings ended as the reviews last reported them, and why people dismissed theirs', function () {
    $this->run->update(['pull_request_id' => 7, 'started_at' => now()->subHours(3), 'findings' => [
        ['id' => 'F1', 'status' => 'new'], ['id' => 'F6', 'status' => 'fixed'],
    ]]);
    $next = fn (array $attributes) => tap($this->run->replicate()->fill(['uuid' => (string) Str::uuid()] + $attributes))->save();

    // F6 was settled before and is left out of the newest review: it stays fixed.
    $newest = $next(['started_at' => now()->subHours(2), 'findings' => [
        ['id' => 'F1', 'status' => 'fixed'], ['id' => 'F2', 'status' => 'false_positive'], ['id' => 'F3', 'status' => 'acknowledged'],
        ['id' => 'F4', 'status' => 'new'], ['id' => 'F5', 'status' => 'outdated'], ['id' => 'F7', 'status' => 'unreported'],
    ]]);
    // Neither a trial nor a review that failed to publish stands on the pull request.
    $next(['started_at' => now()->subHour(), 'custom' => true, 'findings' => [['id' => 'F1', 'status' => 'new']]]);
    $next(['started_at' => now(), 'status' => ReviewRun::STATUS_FAILED, 'findings' => [['id' => 'F1', 'status' => 'new']]]);

    app(FindingFeedback::class)->set($newest, $this->user, 'F2', FindingVerdictKind::Wrong, FindingVerdictReason::Misread);
    app(FindingFeedback::class)->set($newest, $this->user, 'F3', FindingVerdictKind::NotAProblem, FindingVerdictReason::Intended, 'Kept for old clients.');

    $outcomes = app(TenantOverview::class)->for($this->tenant, 30)['outcomes'];

    expect(collect($outcomes['outcomes'])->pluck('count', 'key')->all())->toBe(['fixed' => 2, 'acknowledged' => 1, 'false_positive' => 1, 'open' => 1, 'outdated' => 1, 'unreported' => 1])
        ->and($outcomes['total'])->toBe(7)
        ->and(collect($outcomes['reasons'])->pluck('count', 'label')->all())->toBe(['Intended' => 1, 'Misread the code' => 1]);
});
