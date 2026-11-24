<?php

use App\Exceptions\ReviewStopped;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\Sandbox;
use App\Services\ReviewStops;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->work = storage_path('framework/testing/stop-work');
    File::ensureDirectoryExists($this->work);
});

afterEach(fn () => File::deleteDirectory($this->work));

it('stops a step on Docker within seconds, and removes its container', function () {
    // Stands in for docker: a step that would run for half a minute, and a removal that notes itself.
    $docker = "{$this->work}/docker";
    File::put($docker, "#!/bin/sh\necho \"\$*\" >> \"\$0.calls\"\n[ \"\$1\" = run ] && sleep 30\nexit 0\n");
    chmod($docker, 0755);
    config(['review.container.docker' => $docker]);

    $sandbox = new Sandbox;
    $sandbox->stopWhen = fn (): bool => true;
    $started = microtime(true);

    expect(fn () => $sandbox->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 60))->toThrow(ReviewStopped::class);

    expect(microtime(true) - $started)->toBeLessThan(10)
        ->and(File::get("{$docker}.calls"))->toContain('rm --force review-7-41-review');
});

it('hangs up on the Worker when the review is stopped, and lets its sandbox go', function () {
    Storage::fake('r2');
    config(['review.cloudflare' => ['url' => 'https://sandbox.example.com', 'token' => 'worker-token', 'disk' => 'r2', 'instance' => 'basic', 'local' => false] + config('review.cloudflare')]);
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => fn (Request $request) => $request->method() === 'POST'
        ? Http::response(": keep-alive\n\n".str_repeat("event: output\ndata: \"thinking\"\n\n", 3), 200, ['Content-Type' => 'text/event-stream'])
        : Http::response(['destroyed' => true])]);

    $sandbox = app(CloudflareSandbox::class);
    $sandbox->stopWhen = fn (): bool => true;

    expect(fn () => $sandbox->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 1800))->toThrow(ReviewStopped::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/runs/review-7-41-review-'));
});

it('has a Stop button on a review that runs, and none once it is done', function (string $status, bool $visible) {
    $repository = Repository::factory()->create();
    $user = User::factory()->create(['name' => 'Robin Reviewer']);
    $user->tenants()->attach($repository->tenant_id);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($repository->tenant);
    $run = ReviewRun::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'build_number' => 41, 'status' => $status, 'started_at' => now(),
    ]);

    $page = livewire(ViewReviewRun::class, ['record' => $run->uuid]);

    if (! $visible) {
        $page->assertActionHidden('stop');

        return;
    }

    $page->callAction('stop')->assertNotified('The review is stopping');

    expect(app(ReviewStops::class)->requested($repository, 41))->toBe('Robin Reviewer');
})->with([
    'running' => [ReviewRun::STATUS_RUNNING, true],
    'finished' => [ReviewRun::STATUS_FINISHED, false],
]);
