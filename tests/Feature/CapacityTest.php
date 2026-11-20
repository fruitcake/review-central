<?php

use App\Jobs\RunContainerReview;
use App\Models\CapacitySample;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\Capacity;
use App\Services\SystemLoad;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');

    $this->sample = fn (int $minutesAgo, array $attributes = []): CapacitySample => CapacitySample::query()->create($attributes + [
        'sampled_at' => now()->subMinutes($minutesAgo), 'running' => 0, 'waiting' => 0, 'workers' => 2,
        'load' => 0.4, 'cpus' => 4, 'memory_used_percent' => 40, 'disk_used_percent' => 30,
    ]);

    $this->finished = function (?int $waited, int $duration): ReviewRun {
        static $build = 0;
        $repository = Repository::query()->first() ?? Repository::factory()->create();

        return ReviewRun::query()->create([
            'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'main',
            'commit' => str_pad((string) ++$build, 40, 'a'), 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now()->subHour(),
            'duration_seconds' => $duration, 'queue_seconds' => $waited,
        ]);
    };
});

it('samples how many reviews run and wait, and how loaded the server is', function () {
    config(['review.container.processes' => 3, 'review.container.queue' => 'reviews', 'review.container.connection' => null]);

    Queue::fake();
    RunContainerReview::dispatch(1, 'main', null, 1)->onQueue('reviews');
    RunContainerReview::dispatch(1, 'main', null, 2)->onQueue('reviews');

    $repository = Repository::factory()->create();
    foreach ([
        ['docker', ReviewRun::STATUS_RUNNING, now()],
        ['pipeline', ReviewRun::STATUS_RUNNING, now()],
        // Turned away behind the running review of its pull request.
        ['docker', ReviewRun::STATUS_PENDING, now()->subMinutes(3)],
        // Of another runner, and one whose pull request was left long ago.
        ['pipeline', ReviewRun::STATUS_PENDING, now()],
        ['docker', ReviewRun::STATUS_PENDING, now()->subHours(3)],
    ] as $index => [$runner, $status, $startedAt]) {
        ReviewRun::query()->create([
            'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'main',
            'commit' => str_repeat((string) $index, 40), 'runner' => $runner, 'status' => $status, 'started_at' => $startedAt,
        ]);
    }

    $this->mock(SystemLoad::class, function ($mock): void {
        $mock->shouldReceive('load')->andReturn(3.0);
        $mock->shouldReceive('processors')->andReturn(4);
        $mock->shouldReceive('memoryUsedPercent')->andReturn(61.5);
        $mock->shouldReceive('diskUsedPercent')->andReturn(44.0);
    });

    // Older than the month that is kept.
    ($this->sample)(60 * 24 * 31);

    $this->artisan('review:sample-capacity')->assertSuccessful();

    $sample = CapacitySample::query()->sole();

    // Only the container review counts as running here; the pipeline's runs elsewhere.
    expect($sample->only(['running', 'waiting', 'held', 'workers', 'cpus']))->toBe(['running' => 1, 'waiting' => 2, 'held' => 1, 'workers' => 3, 'cpus' => 4])
        ->and((float) $sample->load)->toBe(3.0)
        ->and((float) $sample->memory_used_percent)->toBe(61.5)
        ->and((float) $sample->disk_used_percent)->toBe(44.0)
        ->and($sample->sampled_at->toDateTimeString())->toBe('2026-11-18 12:00:00');
});

it('says the installation has room when nothing waits', function () {
    ($this->sample)(3, ['running' => 1]);
    ($this->sample)(2);
    ($this->finished)(4, 100);
    ($this->finished)(10, 200);
    // Before the period.
    ($this->sample)(60 * 30, ['running' => 2, 'waiting' => 5]);

    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['samples'])->toBe(2)
        ->and($report['wait'])->toBe(['average' => 7, 'p90' => 10, 'max' => 10])
        ->and($report['duration'])->toBe(['average' => 150, 'p90' => 200, 'max' => 200])
        ->and($report['saturated'])->toBe(0)
        ->and($report['queued'])->toBe(0)
        ->and($report['peak_running'])->toBe(1)
        ->and($report['cpu'])->toBe(['average' => 10, 'peak' => 10, 'cpus' => 4])
        ->and($report['memory'])->toBe(['average' => 40, 'peak' => 40])
        ->and($report['disk'])->toBe(30)
        ->and($report['now'])->toMatchArray(['running' => 0, 'waiting' => 0, 'workers' => 2])
        ->and(array_column($report['advice'], 'title'))->toBe(['The installation has room']);
});

it('advises more workers when reviews wait and the server has room', function () {
    ($this->sample)(4, ['running' => 2, 'waiting' => 1]);
    ($this->sample)(3, ['running' => 2]);
    ($this->sample)(2);
    ($this->sample)(1);
    ($this->finished)(300, 100);

    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['saturated'])->toBe(50)
        ->and($report['queued'])->toBe(25)
        ->and($report['peak_waiting'])->toBe(1)
        ->and($report['advice'])->toHaveCount(1)
        ->and($report['advice'][0]['title'])->toBe('Reviews wait for a worker while the server has room')
        ->and($report['advice'][0]['body'])->toContain('One review in ten waits 05:00 or longer', 'Every worker was busy 50% of the time', 'REVIEW_CONTAINER_PROCESSES');
});

it('advises a bigger server when reviews wait and the server is full, and names a full disk', function () {
    ($this->sample)(2, ['running' => 2, 'waiting' => 2, 'load' => 3.8, 'memory_used_percent' => 91, 'disk_used_percent' => 88]);
    ($this->sample)(1, ['running' => 2, 'load' => 3.6, 'memory_used_percent' => 80, 'disk_used_percent' => 88]);

    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['cpu'])->toBe(['average' => 93, 'peak' => 95, 'cpus' => 4])
        ->and(array_column($report['advice'], 'title'))->toBe(['Reviews wait, and the server is full', 'The disk is filling up'])
        ->and($report['advice'][0]['body'])->toContain('95% processor and 91% memory');
});

it('names the processors when nothing waits but the load is at its limit', function () {
    $repository = Repository::factory()->create(['review_runner' => 'docker']);
    ($this->sample)(1, ['running' => 1, 'load' => 3.9]);

    expect(array_column(app(Capacity::class)->report(now()->subDay())['advice'], 'title'))->toBe(['The processors are the limit']);

    // Moved to Cloudflare since: the load of the reviews on Docker before says nothing of now.
    $repository->update(['review_runner' => 'cloudflare']);

    expect(array_column(app(Capacity::class)->report(now()->subDay())['advice'], 'title'))->toBe(['The installation has room']);
});

it('counts only the load while reviews ran on Docker', function () {
    Repository::factory()->create(['review_runner' => 'docker']);
    ($this->sample)(2, ['running' => 0, 'load' => 3.9]);
    ($this->sample)(1, ['running' => 1, 'load' => 1.0]);

    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['docker']['cpu_peak'])->toBe(25)
        ->and(array_column($report['advice'], 'title'))->toBe(['The installation has room']);
});

it('advises more workers, not a bigger server, when the waiting reviews run in Cloudflare sandboxes', function () {
    ($this->sample)(2, ['running' => 2, 'running_sandboxes' => 2, 'waiting' => 2, 'load' => 3.8]);
    ($this->sample)(1, ['running' => 2, 'running_sandboxes' => 2, 'load' => 3.6]);
    ($this->finished)(300, 100)->update(['runner' => 'cloudflare']);
    ($this->finished)(200, 100)->update(['runner' => 'cloudflare']);

    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['sandbox_share'])->toBe(100)
        ->and($report['now']['running_sandboxes'])->toBe(2)
        ->and($report['trend'][0]['sandboxes'])->toBe(2)
        ->and(array_column($report['advice'], 'title'))->toBe(['Reviews wait for a worker'])
        ->and($report['advice'][0]['body'])->toContain('ran in a Cloudflare sandbox', 'REVIEW_CONTAINER_PROCESSES');
});

it('leaves the processors out of the advice when every review ran in a sandbox', function () {
    ($this->sample)(1, ['running' => 1, 'running_sandboxes' => 1, 'load' => 3.9]);
    ($this->finished)(0, 100)->update(['runner' => 'cloudflare']);

    expect(array_column(app(Capacity::class)->report(now()->subDay())['advice'], 'title'))->toBe(['The installation has room']);
});

it('says so when there is nothing measured yet', function () {
    $report = app(Capacity::class)->report(now()->subDay());

    expect($report['now'])->toBeNull()
        ->and($report['wait'])->toBeNull()
        ->and(array_column($report['advice'], 'title'))->toBe(['No measurements yet']);
});

it('does not count a review a worker is running as one that waits', function () {
    config(['review.container.queue' => 'reviews', 'review.container.connection' => null]);

    // The queue's size also counts the jobs being run; only those not picked up wait.
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('pendingSize')->with('reviews')->andReturn(0);
    $queue->shouldNotReceive('size');
    Queue::shouldReceive('connection')->andReturn($queue);

    $this->artisan('review:sample-capacity')->assertSuccessful();

    expect(CapacitySample::query()->sole()->waiting)->toBe(0);
});

it('draws reviews held behind another review apart from those waiting for a worker', function () {
    ($this->sample)(3, ['running' => 1, 'waiting' => 0, 'held' => 2]);
    ($this->sample)(1, ['running' => 2, 'waiting' => 1, 'held' => 0]);

    $report = app(Capacity::class)->report(now()->subHour());

    expect($report['now'])->toMatchArray(['running' => 2, 'waiting' => 1, 'held' => 0])
        ->and($report['peak_waiting'])->toBe(1)
        ->and($report['peak_held'])->toBe(2)
        ->and(collect($report['trend'])->pluck('held')->all())->toBe([2, 0]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin')->assertOk()
        ->assertSee('1 waiting for a worker, 0 behind another review')
        ->assertSee('Behind another review of its pull request');
});
