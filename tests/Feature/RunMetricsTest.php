<?php

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\RunLog;
use App\Services\Capacity;
use App\Services\PlatformSettings;
use App\Services\RunMetrics;
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
        ->and(app(Capacity::class)->report(now()->subDays(30))['advice'])->toContain(['tone' => 'changes', 'label' => 'Change size', 'title' => 'Sandbox size', 'body' => $sizing['advice']]);
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

it('runs sandboxes in the size picked under Settings, where the environment picks none', function () {
    expect(CloudflareSandbox::instance())->toBe('standard-1');

    app(PlatformSettings::class)->save([PlatformSettings::CLOUDFLARE_INSTANCE => 'standard-3']);
    app(PlatformSettings::class)->applyCloudflare();

    expect(CloudflareSandbox::instance())->toBe('standard-3');

    config(['review.cloudflare.instance' => 'huge']);

    expect(CloudflareSandbox::instance())->toBe('standard-1');
});
