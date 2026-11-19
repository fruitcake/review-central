<?php

use App\Filament\Admin\Pages\Platform;
use App\Filament\App\Pages\Overview;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRating;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformOverview;
use App\Services\TenantOverview;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');

    $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'monthly_budget' => 10]);
    $this->connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create([
        'tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'slug' => 'shop',
        'review_stack' => ['laravel'],
    ]);

    config(['review.docker.enabled' => true]);

    $this->run = function (array $attributes = []): ReviewRun {
        static $build = 0;

        return ReviewRun::query()->create($attributes + [
            'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
            'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_pad((string) ++$build, 40, 'a'), 'build_number' => $build,
            'status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED', 'started_at' => now()->subDay(), 'duration_seconds' => 100, 'cost' => 1,
        ]);
    };

    $this->finding = fn (string $id, string $severity, string $status, string $category): array => [
        'id' => $id, 'severity' => $severity, 'status' => $status, 'category' => $category, 'title' => 'A finding',
    ];

    $this->figure = fn (array $overview, string $label): array => collect($overview['figures'])->firstWhere('label', $label);
});

it('counts the period\'s reviews, how they ended and what they cost against the budget', function () {
    ($this->run)(['duration_seconds' => 60, 'cost' => 2.5]);
    ($this->run)(['duration_seconds' => 120, 'result' => 'FAILED', 'cost' => 1]);
    ($this->run)(['duration_seconds' => 300, 'cost' => 0.5]);
    ($this->run)(['status' => ReviewRun::STATUS_TIMEOUT, 'result' => null, 'duration_seconds' => 3600, 'cost' => null]);
    // Turned away by the lock: it reviewed nothing.
    ($this->run)(['status' => ReviewRun::STATUS_PENDING, 'result' => null, 'cost' => null]);
    // Before the period, and the whole of the period before it.
    ($this->run)(['started_at' => now()->subDays(10)]);
    ($this->run)(['started_at' => now()->subDays(11)]);

    $overview = app(TenantOverview::class)->for($this->tenant, 7);

    expect(($this->figure)($overview, 'Reviews'))->toMatchArray(['value' => '4', 'note' => '+100% vs previous 7d'])
        ->and(($this->figure)($overview, 'Changes requested'))->toMatchArray(['value' => '33%', 'note' => '1 of 3 finished'])
        ->and(($this->figure)($overview, 'Median duration'))->toMatchArray(['value' => '02:00', 'note' => 'p90 05:00'])
        ->and(($this->figure)($overview, 'Did not finish'))->toMatchArray(['value' => '1', 'note' => '25% of runs', 'tone' => 'danger'])
        // The month's runs, also those before the period: 2.5, 1, 0.5, 1 and 1 dollar.
        ->and(($this->figure)($overview, 'Cost this month'))->toMatchArray(['value' => '$6.00', 'note' => '60% of $10 budget', 'bar' => 60, 'tone' => 'neutral']);

    $today = collect($overview['days']['bars'])->firstWhere('date', '2026-11-17');

    expect($today)->toBe(['date' => '2026-11-17', 'approved' => 2, 'changes' => 1])
        ->and($overview['days']['max'])->toBe(3)
        ->and($overview['days']['bars'])->toHaveCount(7);
});

it('says how many of the rated findings the team accepted, per category', function () {
    $run = ($this->run)(['result' => 'FAILED', 'findings' => [
        ($this->finding)('F1', 'HIGH', 'new', 'security'),
        ($this->finding)('F2', 'LOW', 'new', 'tests'),
        // Carried over from an earlier run: it was counted when it was new.
        ($this->finding)('F3', 'LOW', 'open', 'tests'),
    ]]);

    ReviewRating::query()->create(['review_run_id' => $run->id, 'score' => 4, 'findings' => ['F1' => true, 'F2' => false]]);
    ReviewRating::query()->create(['review_run_id' => $run->id, 'score' => 5, 'findings' => ['F2' => true]]);

    $overview = app(TenantOverview::class)->for($this->tenant, 30);
    $categories = collect($overview['categories'])->keyBy('name');

    expect(($this->figure)($overview, 'Findings accepted'))->toMatchArray(['value' => '67%', 'note' => '2 of 3 rated findings'])
        ->and($categories['Security'])->toBe(['name' => 'Security', 'findings' => 1, 'rated' => 1, 'accepted' => 1])
        ->and($categories['Tests'])->toBe(['name' => 'Tests', 'findings' => 1, 'rated' => 2, 'accepted' => 1])
        ->and($categories['Performance'])->toBe(['name' => 'Performance', 'findings' => 0, 'rated' => 0, 'accepted' => 0])
        ->and($overview['repositories'][0])->toMatchArray(['reviews' => 1, 'changes' => '100%', 'accepted' => '67%', 'ready' => true]);
});

it('points at what keeps a tenant from being reviewed well', function () {
    // No AI provider, no runner that is set up, an old high finding and a failed run.
    config(['review.docker.enabled' => false, 'review.diff.enabled' => false]);
    ($this->run)(['started_at' => now()->subDays(9), 'result' => 'FAILED', 'findings' => [($this->finding)('F1', 'HIGH', 'new', 'security')]]);
    ($this->run)(['status' => ReviewRun::STATUS_FAILED, 'result' => null]);

    $attention = collect(app(TenantOverview::class)->for($this->tenant, 30)['attention']);

    expect($attention->pluck('title')->all())->toBe([
        'No AI provider yet',
        'shop cannot start reviews',
        '1 high finding open for over 7 days',
        '1 review did not finish',
    ])->and($attention->pluck('target')->all())->toBe(['ai-providers', 'repositories', 'action', 'failed']);

    // The pull request was reviewed again since, and the finding is gone.
    ($this->run)(['started_at' => now()->subDays(2), 'findings' => [($this->finding)('F1', 'HIGH', 'fixed', 'security')]]);
    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);
    config(['review.docker.enabled' => true]);

    expect(collect(app(TenantOverview::class)->for($this->tenant, 30)['attention'])->pluck('title')->all())->toBe([
        '1 review did not finish',
    ]);
});

it('shows the overview and the views of the list to a tenant\'s user', function () {
    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    $approved = ($this->run)();
    $blocked = ($this->run)(['result' => 'FAILED', 'findings' => [($this->finding)('F1', 'HIGH', 'new', 'security')]]);
    $running = ($this->run)(['status' => ReviewRun::STATUS_RUNNING, 'result' => null]);
    $failed = ($this->run)(['status' => ReviewRun::STATUS_FAILED, 'result' => null]);
    $waiting = ($this->run)(['status' => ReviewRun::STATUS_PENDING, 'result' => null]);

    livewire(Overview::class)
        ->assertOk()
        ->assertSee('Acme · last 30 days')
        ->assertSee('1 review did not finish')
        ->set('days', 7)
        ->assertSee('Acme · last 7 days')
        // Not one of the three periods: typed into the address.
        ->set('days', 5000)
        ->assertSee('Acme · last 30 days');

    livewire(ListReviewRuns::class)
        ->assertSee('4 reviews in the last 30 days · 1 running now')
        ->assertCanSeeTableRecords([$approved, $blocked, $running, $failed])
        ->assertCanNotSeeTableRecords([$waiting])
        ->assertSee('1 high')
        ->set('activeTab', 'action')
        ->assertCanSeeTableRecords([$blocked, $failed])
        ->assertCanNotSeeTableRecords([$approved, $running])
        ->set('activeTab', 'running')
        ->assertCanSeeTableRecords([$running])
        ->assertCanNotSeeTableRecords([$approved, $blocked, $failed])
        ->set('activeTab', 'other')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$approved]);
});

it('shows an administrator every tenant with its cost and what blocks it', function () {
    $empty = Tenant::factory()->create(['name' => 'Newcomer', 'slug' => 'newcomer']);
    $blocked = Tenant::factory()->create(['name' => 'Blocked', 'slug' => 'blocked']);
    Repository::factory()->create(['tenant_id' => $blocked->id, 'connection_id' => Connection::factory()->create(['tenant_id' => $blocked->id])->id]);
    // The runner its repository picked is turned off.
    Repository::query()->where('tenant_id', $blocked->id)->update(['review_runner' => 'docker']);
    config(['review.docker.enabled' => false]);

    ($this->run)(['cost' => 9]);
    ($this->run)(['status' => ReviewRun::STATUS_FAILED, 'result' => null, 'cost' => null]);

    $platform = app(PlatformOverview::class)->for(now()->subDays(7));
    $rows = collect($platform['tenants'])->keyBy(fn (array $row): string => $row['tenant']->slug);

    expect($rows['acme'])->toMatchArray(['reviews' => 2, 'spent' => 9.0, 'budget' => 10.0, 'percent' => 90, 'health' => ['label' => 'Near budget', 'tone' => 'failed'], 'connections' => 'Bitbucket Cloud'])
        ->and($rows['newcomer']['health'])->toBe(['label' => 'Not set up', 'tone' => 'neutral'])
        ->and($rows['blocked']['health'])->toBe(['label' => '1 repo blocked', 'tone' => 'changes'])
        ->and(collect($platform['figures'])->pluck('value', 'label')->all())->toBe([
            'Active tenants' => '1', 'Reviews' => '2', 'Model cost' => '$9.00', 'Did not finish' => '50%', 'Findings accepted' => '—',
        ]);

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(Platform::class)
        ->assertOk()
        ->assertSee('All tenants · last 7 days')
        ->assertSee('Near budget')
        ->assertSee('/app/newcomer')
        ->assertSee('No measurements yet');
});

it('shows times in the tenant\'s time zone, and counts a review on the tenant\'s day', function () {
    $this->tenant->update(['timezone' => 'Europe/Amsterdam']);

    // Half past midnight on 18 November in Amsterdam, which is still the 17th in UTC.
    $run = ($this->run)(['started_at' => '2026-11-17 23:30:00']);

    $days = collect(app(TenantOverview::class)->for($this->tenant->refresh(), 7)['days']['bars'])->pluck('approved', 'date');

    expect($this->tenant->timezone)->toBe('Europe/Amsterdam')
        ->and($days['2026-11-18'])->toBe(1)
        ->and($days['2026-11-17'])->toBe(0);

    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);

    $this->actingAs($user)->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('18 Nov 2026, 00:30:00');

    // Another tenant reads the same moment in its own zone.
    $this->tenant->update(['timezone' => 'America/New_York']);

    $this->actingAs($user)->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('17 Nov 2026, 18:30:00');
});
