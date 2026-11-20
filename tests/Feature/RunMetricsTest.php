<?php

use App\Filament\Admin\Pages\Platform;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\RunLog;
use App\Services\Capacity;
use App\Services\PlatformSettings;
use App\Services\RunMetrics;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['review.container.log.enabled' => true, 'review.container.log.directory' => storage_path('framework/testing/metric-logs'), 'review.cloudflare.instance' => null]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/metric-logs'));
});

it('notes when each step starts, from the runner\'s notes and the scripts\' marks, and nothing the reviewer names', function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $log = app(RunLog::class);
    $writer = $log->writer(7, 41);

    $writer->note('── precheck ──');
    $this->travel(12)->seconds();
    $writer->write('out', "##[phase] composer\nInstalling\n");
    $this->travel(30)->seconds();
    // What the reviewer prints is no step, nor is a step named twice.
    $writer->write('out', "##[phase] secrets\n##[phase] precheck\n##[phase] reviewer\n");

    expect($log->phases(7, 41))->toBe([
        ['at' => 1_800_000_000, 'name' => 'precheck'],
        ['at' => 1_800_000_012, 'name' => 'composer'],
        ['at' => 1_800_000_042, 'name' => 'reviewer'],
    ])
        // On the page, the mark reads as the runner's own.
        ->and(File::get($log->path(7, 41)))->toContain('── composer ──')
        ->and(File::get($log->path(7, 41)))->not->toContain('##[phase] composer');
});

it('keeps a step that ran from a kept vendor/ apart from one that installed it from nothing', function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $log = app(RunLog::class);

    $log->writer(7, 51)->write('out', "##[phase] composer cached\n");
    $log->writer(7, 52)->write('out', "##[phase] composer fresh\n");
    // Only the ways there are: anything else is the step as it is.
    $log->writer(7, 53)->write('out', "##[phase] composer whatever\n");

    expect(array_column($log->phases(7, 51), 'name'))->toBe(['composer (cached)'])
        ->and(array_column($log->phases(7, 52), 'name'))->toBe(['composer (fresh)'])
        ->and(array_column($log->phases(7, 53), 'name'))->toBe(['composer'])
        ->and(File::get($log->path(7, 51)))->toContain('── composer (cached) ──');
});

it('sums up what each step took and used', function () {
    $metrics = RunMetrics::summarize(
        [['at' => 100, 'name' => 'composer'], ['at' => 130, 'name' => 'reviewer']],
        [[100, 0.24, 400, 0.3], [110, 0.25, 900, 0.0], [130, 0.05, 600, 0.0], [190, 0.1, 650, 0.0]],
        0.25,
        1024,
        250,
    );

    expect($metrics)->toMatchArray([
        'processors' => 0.25, 'memory_mb' => 1024, 'peak_memory_mb' => 900, 'cpu_average' => 0.16, 'cpu_peak' => 0.25, 'starved_percent' => 50,
    ])
        ->and($metrics['steps'])->toBe([
            ['name' => 'composer', 'seconds' => 30, 'peak_memory_mb' => 900, 'cpu_average' => 0.25, 'cpu_peak' => 0.25, 'starved_percent' => 100],
            ['name' => 'reviewer', 'seconds' => 120, 'peak_memory_mb' => 650, 'cpu_average' => 0.08, 'cpu_peak' => 0.1, 'starved_percent' => 0],
        ]);
});

/**
 * @param  array<string, mixed>  $metrics
 */
function sandboxRun(Repository $repository, int $build, string $size, array $metrics): void
{
    ReviewRun::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/x', 'commit' => str_repeat('a', 40), 'build_number' => $build, 'status' => ReviewRun::STATUS_FINISHED,
        'started_at' => now()->subDay(), 'sandbox' => 'cloudflare', 'sandbox_size' => $size, 'metrics' => $metrics,
    ]);
}

it('advises the size the sandboxes\' own measurements fit', function () {
    $repository = Repository::factory()->create();
    config(['review.cloudflare.instance' => 'standard-2']);

    expect(app(Capacity::class)->sizing()['advice'])->toBeNull();

    // Little memory, and processor to spare around the model: a smaller one fits.
    foreach (range(1, 6) as $build) {
        sandboxRun($repository, $build, 'standard-2', ['peak_memory_mb' => 600 + $build * 30, 'steps' => [
            ['name' => 'composer', 'seconds' => 20, 'starved_percent' => 0],
            ['name' => 'reviewer', 'seconds' => 200, 'starved_percent' => 90],
        ]]);
    }

    $sizing = app(Capacity::class)->sizing();

    expect($sizing)->toMatchArray(['instance' => 'standard-2', 'runs' => 6, 'peak_memory_mb' => 780, 'starved_percent' => 0, 'suggested' => 'basic'])
        ->and($sizing['advice'])->toContain('basic (1 GiB) fits as well and costs')
        ->and(app(Capacity::class)->report(now()->subDays(30))['advice'])->toContain(['tone' => 'changes', 'label' => 'Change size', 'title' => 'Sandbox size', 'body' => $sizing['advice'], 'apply' => 'basic', 'settings' => true]);
});

it('advises a bigger size when the steps around the model are short of processor', function () {
    $repository = Repository::factory()->create();
    config(['review.cloudflare.instance' => 'basic']);

    foreach (range(1, 5) as $build) {
        sandboxRun($repository, $build, 'basic', ['peak_memory_mb' => 500, 'steps' => [
            ['name' => 'composer', 'seconds' => 60, 'starved_percent' => 60],
            ['name' => 'reviewer', 'seconds' => 200, 'starved_percent' => 0],
        ]]);
    }

    $sizing = app(Capacity::class)->sizing();

    expect($sizing['suggested'])->toBe('standard-1')
        ->and($sizing['starved_percent'])->toBe(60)
        ->and($sizing['advice'])->toContain('basic is short of processor');
});

it('waits after a change of size, and never sends it back to a size that was short of processor', function () {
    $repository = Repository::factory()->create();
    $starved = ['peak_memory_mb' => 500, 'steps' => [['name' => 'composer', 'seconds' => 60, 'starved_percent' => 84], ['name' => 'reviewer', 'seconds' => 200, 'starved_percent' => 0]]];
    $roomy = ['peak_memory_mb' => 500, 'steps' => [['name' => 'composer', 'seconds' => 60, 'starved_percent' => 5], ['name' => 'reviewer', 'seconds' => 200, 'starved_percent' => 0]]];

    foreach (range(1, 5) as $build) {
        sandboxRun($repository, $build, 'standard-1', $starved);
    }

    config(['review.cloudflare.instance' => 'standard-1']);
    expect(app(Capacity::class)->sizing())->suggested->toBe('standard-2')->measuring->toBeFalse();

    // Moved up: nothing yet of standard-2's processor, so no advice to go back down.
    config(['review.cloudflare.instance' => 'standard-2']);
    sandboxRun($repository, 6, 'standard-2', $roomy);

    expect(app(Capacity::class)->sizing())->suggested->toBe('standard-2')->measuring->toBeTrue()
        ->and(app(Capacity::class)->report(now()->subDays(30))['advice'])->toContain(['tone' => 'neutral', 'label' => 'Measuring', 'title' => 'Sandbox size', 'body' => app(Capacity::class)->sizing()['advice']]);

    // Measured: its memory alone would fit basic, but standard-1 was already short of processor.
    foreach (range(7, 10) as $build) {
        sandboxRun($repository, $build, 'standard-2', $roomy);
    }

    expect(app(Capacity::class)->sizing())->suggested->toBe('standard-2')->measuring->toBeFalse()
        ->and(app(Capacity::class)->sizing()['advice'])->toContain('standard-2 (6 GiB) fits');
});

it('runs sandboxes in the size picked under Settings, where the environment picks none', function () {
    expect(CloudflareSandbox::instance())->toBe('standard-1');

    app(PlatformSettings::class)->save([PlatformSettings::CLOUDFLARE_INSTANCE => 'standard-3']);
    app(PlatformSettings::class)->applyCloudflare();

    expect(CloudflareSandbox::instance())->toBe('standard-3');

    config(['review.cloudflare.instance' => 'huge']);

    expect(CloudflareSandbox::instance())->toBe('standard-1');
});

it('sums up each step of the reviews in a sandbox, per size', function () {
    $repository = Repository::factory()->create();

    foreach ([[1, 40, 300], [2, 60, 500], [3, 50, 400]] as [$build, $composer, $reviewer]) {
        sandboxRun($repository, $build, 'standard-2', ['peak_memory_mb' => 700, 'steps' => [
            ['name' => 'composer', 'seconds' => $composer, 'peak_memory_mb' => 600 + $build, 'starved_percent' => 10],
            ['name' => 'reviewer', 'seconds' => $reviewer, 'peak_memory_mb' => 400, 'starved_percent' => 0],
        ]]);
    }
    sandboxRun($repository, 4, 'standard-1', ['peak_memory_mb' => 700, 'steps' => [['name' => 'composer', 'seconds' => 90, 'starved_percent' => 80]]]);

    $sizes = collect(app(Capacity::class)->sandboxSteps(now()->subDays(7)))->keyBy('size');

    expect($sizes->keys()->sort()->values()->all())->toBe(['standard-1', 'standard-2'])
        ->and($sizes['standard-2']['runs'])->toBe(3)
        ->and($sizes['standard-2']['steps'])->toBe([
            ['name' => 'composer', 'runs' => 3, 'average' => 50, 'p90' => 60, 'peak_memory_mb' => 603, 'starved_percent' => 10],
            ['name' => 'reviewer', 'runs' => 3, 'average' => 400, 'p90' => 500, 'peak_memory_mb' => 400, 'starved_percent' => 0],
        ])
        ->and($sizes['standard-1']['steps'][0])->toMatchArray(['average' => 90, 'starved_percent' => 80]);
});

it('applies the advised size from the Platform page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    foreach (range(1, 5) as $build) {
        sandboxRun(Repository::factory()->create(), $build, 'standard-1', ['peak_memory_mb' => 500, 'steps' => [['name' => 'composer', 'seconds' => 60, 'starved_percent' => 84], ['name' => 'reviewer', 'seconds' => 200, 'starved_percent' => 0]]]);
    }

    Pest\Livewire\livewire(Platform::class)
        ->assertSee(['Use standard-2', 'Open Settings', 'Waited for processor', 'composer']);

    Pest\Livewire\livewire(Platform::class)
        ->callAction(TestAction::make('applySize')->arguments(['size' => 'standard-2']))
        ->assertNotified('Sandboxes run on standard-2 from the next review on');

    expect(CloudflareSandbox::instance())->toBe('standard-2');

    // Only a size there is.
    Pest\Livewire\livewire(Platform::class)
        ->callAction(TestAction::make('applySize')->arguments(['size' => 'huge']));

    expect(CloudflareSandbox::instance())->toBe('standard-2');
});
