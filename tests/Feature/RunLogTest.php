<?php

use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runners\Container\RunLog;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'slug' => 'shop', 'review_runner' => 'docker']);

    $this->run = fn (string $status, int $build = 41): ReviewRun => ReviewRun::query()->create([
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_repeat('a', 40), 'build_number' => $build,
        'runner' => 'docker', 'status' => $status, 'started_at' => now(),
    ]);

    $this->logs = app(RunLog::class);

    $user = User::factory()->create();
    $user->tenants()->attach($this->tenant);
    $this->actingAs($user);
});

it('writes whole lines, however the output is cut into pieces', function () {
    $writer = $this->logs->writer($this->repository->id, 41, ['ANTHROPIC_API_KEY' => 'sk-ant-one-secret', 'BITBUCKET_BRANCH' => 'feature/export']);

    // The secret arrives split over two pieces.
    $writer->write('out', "first line\nUsing sk-ant-");
    $writer->write('out', "one-secret on feature/export\r\nprogress 10%\rprogress 100%\nunfinished");

    $log = File::get($this->logs->path($this->repository->id, 41));

    expect($log)->toContain("  first line\n")
        // A value that is no secret stays readable.
        ->toContain("  Using [secret] on feature/export\n")
        ->toContain("  progress 100%\n")
        ->not->toContain('progress 10%')
        ->not->toContain('unfinished');

    $writer->flush();

    expect(File::get($this->logs->path($this->repository->id, 41)))->toEndWith("  unfinished\n");
});

it('shows the log on the review\'s page, and asks again for as long as the review runs', function () {
    $run = ($this->run)(ReviewRun::STATUS_RUNNING);
    $this->logs->writer($this->repository->id, 41)->note('Reading <the> diff');

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()
        ->assertSee('Reading &lt;the&gt; diff', false)
        ->assertSee('follows the review as it runs')
        ->assertSee('wire:poll.3s', false);

    $run->update(['status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED']);

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()
        ->assertSee('Reading &lt;the&gt; diff', false)
        ->assertSee('what the review printed')
        ->assertDontSee('wire:poll', false)
        // Rendering again leaves the log as the reader has it, open or closed.
        ->assertSee('<details wire:ignore.self', false);
});

it('shows no log for a run that has none', function () {
    $run = ($this->run)(ReviewRun::STATUS_RUNNING);

    // It still asks again while the review runs: a log may come, and the run ends.
    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertDontSee('rc-log"', false)->assertSee('wire:poll.3s', false);
});

it('shows the end of a long log, from a whole line', function () {
    $run = ($this->run)(ReviewRun::STATUS_FINISHED);
    $path = $this->logs->path($this->repository->id, 41);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_repeat("an earlier line\n", 20_000)."the last line\n");

    $tail = $this->logs->tail($run);

    expect($tail['truncated'])->toBeTrue()
        ->and(strlen($tail['text']))->toBeLessThanOrEqual(RunLog::TAIL_BYTES)
        ->and($tail['text'])->toStartWith('an earlier line')->toEndWith("the last line\n");
});

it('removes the logs older than the days they are kept', function () {
    config(['review.container.log.days' => 14]);

    $old = $this->logs->path($this->repository->id, 40);
    $recent = $this->logs->path($this->repository->id, 41);

    File::ensureDirectoryExists(dirname($old));
    File::put($old, "old\n");
    File::put($recent, "recent\n");
    touch($old, now()->subDays(15)->getTimestamp());

    $this->artisan('review:prune-logs')->expectsOutput('Removed 1 log(s).')->assertSuccessful();

    expect(File::exists($old))->toBeFalse()->and(File::exists($recent))->toBeTrue();
});

it('lists the findings that hold the merge first, and says which they are', function () {
    $run = ($this->run)(ReviewRun::STATUS_FINISHED);
    $run->update(['result' => 'FAILED', 'agent' => 'claude-code', 'findings' => [
        ['id' => 'F1', 'severity' => 'LOW', 'status' => 'new', 'title' => 'A name that misleads'],
        ['id' => 'F2', 'severity' => 'HIGH', 'status' => 'fixed', 'title' => 'An export that was fixed'],
        ['id' => 'F3', 'severity' => 'HIGH', 'status' => 'open', 'confidence' => 9, 'title' => 'An export of other companies'],
    ]]);

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()
        ->assertSeeInOrder(['Holds the merge', 'An export of other companies', 'A name that misleads', 'An export that was fixed'])
        // The reviewer by its name, not by what the scripts call it.
        ->assertSee('<dd>Claude Code</dd>', false);

    // Once: only the open high finding holds anything.

    expect(substr_count($this->get("/app/acme/review-runs/{$run->uuid}")->getContent(), 'Holds the merge'))->toBe(1);
});

it('marks what holds the merge by the threshold the run was filed with', function () {
    $run = ($this->run)(ReviewRun::STATUS_FINISHED);
    $run->update(['result' => 'PASSED', 'fail_on' => 'high', 'findings' => [
        ['id' => 'F1', 'severity' => 'MEDIUM', 'status' => 'open', 'confidence' => 9, 'title' => 'A total that is off by one'],
    ]]);

    // Approved under its own threshold, so nothing on its page says otherwise.
    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('A total that is off by one')->assertDontSee('Holds the merge');

    // Under the default the same finding holds the merge.
    $run->update(['result' => 'FAILED', 'fail_on' => null]);

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('Holds the merge');
});
