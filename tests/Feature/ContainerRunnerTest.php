<?php

use App\Exceptions\CheckoutFailed;
use App\Exceptions\ReviewStepFailed;
use App\Jobs\RunContainerReview;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\CloudflareRunner;
use App\Runners\Container\Checkout;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\ComposerCache;
use App\Runners\Container\ContainerReview;
use App\Runners\Container\Precheck;
use App\Runners\Container\Report;
use App\Runners\Container\ReviewSessions;
use App\Runners\Container\RunLog;
use App\Runners\Container\RunLogWriter;
use App\Runners\Container\Sandbox;
use App\Runners\Container\Trial;
use App\Runners\ContainerRunner;
use App\Runners\Runners;
use App\Services\PlatformSettings;
use App\Services\ReviewLock;
use App\Services\ReviewSettings;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'review.docker.enabled' => true,
        'review.container.work_directory' => storage_path('framework/testing/review-work'),
        'review.container.cache_directory' => storage_path('framework/testing/review-cache'),
    ]);

    $this->sha = str_repeat('a', 40);
    $this->repository = Repository::factory()->create(['slug' => 'shop', 'review_runner' => 'docker']);
    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/review-work'));
    File::deleteDirectory(storage_path('framework/testing/review-cache'));
});

/**
 * @param  array<string, mixed>  $payload
 */
function deliver(string $event, array $payload): TestResponse
{
    $body = json_encode($payload + ['repository' => ['full_name' => 'acme/shop']]);

    return test()->call('POST', '/api/webhooks/hook-one', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_EVENT_KEY' => $event,
        'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
    ], $body);
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function pullRequestPayload(array $attributes = []): array
{
    return ['pullrequest' => $attributes + [
        'id' => 570,
        'state' => 'OPEN',
        'draft' => false,
        'updated_on' => '2026-11-18T10:00:00+00:00',
        'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat('a', 12)]],
    ]];
}

it('never reviews a pull request from a fork on Bitbucket by itself, which keeps no copy of its branch here', function () {
    Queue::fake();
    $this->repository->tenant->update(['review_forks' => true]);

    deliver('pullrequest:updated', pullRequestPayload([
        'source' => ['branch' => ['name' => 'main'], 'commit' => ['hash' => str_repeat('a', 12)], 'repository' => ['full_name' => 'someone/shop']],
        'destination' => ['branch' => ['name' => 'main'], 'repository' => ['full_name' => 'acme/shop']],
    ]))->assertOk();

    // Its branch is the fork's main: reviewing "main" here would review this repository's.
    Queue::assertNothingPushed();
});

it('starts a review from the webhook when a pull request gets a new commit', function () {
    Queue::fake();
    $other = Repository::factory()->create(['tenant_id' => $this->repository->tenant_id, 'connection_id' => $this->repository->connection_id, 'slug' => 'another']);

    deliver('pullrequest:updated', pullRequestPayload())->assertOk();
    // The host sends a second event for the same push.
    deliver('pullrequest:updated', pullRequestPayload())->assertOk();

    Queue::assertPushedOn('reviews', RunContainerReview::class);
    Queue::assertPushed(RunContainerReview::class, 1);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->repositoryId === $this->repository->id && $job->branch === 'feature/export');

    // A delivery with the right signature is proof that the webhook is in place.
    expect($this->repository->integration->refresh()->webhook_received_at)->not->toBeNull()
        ->and($this->repository->refresh()->webhook_received_at)->not->toBeNull()
        // For this repository only: another one on the connection may have no webhook at all.
        ->and($other->refresh()->webhook_received_at)->toBeNull();
});

it('leaves a draft and a head that is already reviewed alone', function () {
    Queue::fake();

    deliver('pullrequest:updated', pullRequestPayload(['draft' => true]))->assertOk();

    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 570, 'commit' => $this->sha, 'status' => ReviewRun::STATUS_FINISHED, 'started_at' => now(),
    ]);

    // The review's own edit of the description arrives as an update too.
    deliver('pullrequest:updated', pullRequestPayload(['draft' => false, 'updated_on' => '2026-11-18T10:05:00+00:00']))->assertOk();

    Queue::assertNothingPushed();
});

it('keeps the lock of a run that is queued or running, and gives up one that ended', function () {
    $runner = app(ContainerRunner::class);

    $runner->markRunning($this->repository, 41);

    expect($runner->isRunning($this->repository, '41'))->toBeTrue();

    $runner->markEnded($this->repository, 41);

    expect($runner->isRunning($this->repository, '41'))->toBeFalse();
});

/**
 * What the worker's report step was asked to file, in order.
 *
 * @return array<int, array<string, mixed>>
 */
function reports(ArrayObject $steps): array
{
    return array_values(array_map(fn (array $step): array => $step['environment'], array_filter((array) $steps, fn (array $step): bool => $step['command'] === ['worker', 'report'])));
}

/**
 * Fakes docker and git, and says what the precheck decides: that is the worker's
 * own, and is tested by itself.
 *
 * @return array<int, array{command: array<int, string>, environment: array<string, mixed>}>
 */
function fakeSteps(string $needed = 'true', int $reviewerExit = 0, int $precheckExit = 0, ?Closure $whileReviewing = null, string $reviewerPrints = ''): ArrayObject
{
    $steps = new ArrayObject;

    // The precheck is the worker's own; what it would decide is said here.
    app()->bind(Precheck::class, fn () => new class($needed, $precheckExit) extends Precheck
    {
        public function __construct(protected string $needed, protected int $exit) {}

        public function run(Repository $repository, string $branch, string $commit, int $build, string $directory, ?Trial $trial = null, ?RunLogWriter $log = null, string $runner = ContainerRunner::NAME): array
        {
            if ($this->exit !== 0) {
                throw new ReviewStepFailed('The precheck could not open the review.');
            }

            File::put("{$directory}/review-context.json", json_encode(['last_reviewed_commit' => str_repeat('c', 40)]));

            return ['REVIEW_NEEDED' => $this->needed, 'REVIEW_REVIEWER' => 'claude-code', 'REVIEW_PR_ID' => '570', 'REVIEW_DESTINATION_BRANCH' => 'main'];
        }
    });

    // So is the report: here it only notes that it was asked to file one.
    app()->bind(Report::class, fn () => new class($steps) extends Report
    {
        public function __construct(protected ArrayObject $steps) {}

        public function run(Repository $repository, string $branch, string $commit, int $build, int $pullRequestId, string $directory, ?Trial $trial = null, ?RunLogWriter $log = null): bool
        {
            $this->steps[] = ['command' => ['worker', 'report'], 'environment' => ['build' => $build, 'pull_request' => $pullRequestId, 'trial' => $trial !== null]];

            return true;
        }
    });

    Process::fake(function (PendingProcess $process) use ($steps, $reviewerExit, $whileReviewing, $reviewerPrints) {
        $command = (array) $process->command;
        $steps[] = ['command' => $command, 'environment' => $process->environment];

        if ($command[0] === 'git') {
            if ($command[1] === 'clone') {
                File::ensureDirectoryExists(end($command));
            }

            return Process::result(match (true) {
                in_array('rev-parse', $command, true) => str_repeat('a', 40)."\n",
                in_array('ls-remote', $command, true) => str_repeat('b', 40)."\t".end($command)."\n",
                // A merge commit, the destination before it and the branch that went in.
                in_array('rev-list', $command, true) => str_repeat('d', 40).' '.str_repeat('e', 40).' '.str_repeat('f', 40)."\n",
                default => '',
            });
        }

        $script = end($command);

        if ($whileReviewing !== null && str_contains($script, 'run-review.sh')) {
            $whileReviewing();
        }

        return Process::result(output: str_contains($script, 'run-review.sh') ? $reviewerPrints : '', exitCode: match (true) {
            str_contains($script, 'run-review.sh') => $reviewerExit,
            default => 0,
        });
    });

    return $steps;
}

/**
 * @return array{command: array<int, string>, environment: array<string, mixed>}
 */
function stepRunning(ArrayObject $steps, string $script): ?array
{
    foreach ($steps as $step) {
        if ($step['command'][1] === 'run' && str_contains(end($step['command']), $script)) {
            return $step;
        }
    }

    return null;
}

it('gives the reviewer the code and the AI key, and keeps this server\'s token to the steps around it', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    $steps = fakeSteps();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    $review = stepRunning($steps, 'run-review.sh');

    // One container: the reviewer. The precheck and the report are the worker's
    // own, and the report was asked for once, for this build and pull request.
    expect(collect($steps)->filter(fn (array $step): bool => $step['command'][1] === 'run'))->toHaveCount(1)
        ->and(reports($steps))->toBe([['build' => 41, 'pull_request' => 570, 'trial' => false]]);

    expect($review['environment'])->not->toHaveKey('REVIEW_API_KEY')
        ->not->toHaveKey('BITBUCKET_STEP_OIDC_TOKEN')
        ->and($review['environment']['ANTHROPIC_API_KEY'])->toBe('sk-ant-one')
        ->and($review['environment']['BITBUCKET_COMMIT'])->toBe($this->sha)
        ->and($review['environment']['REVIEW_REVIEWER'])->toBe('claude-code');

    // Sandboxed, with the scripts mounted read-only from this application, and no
    // secret on a command line.
    expect($review['command'])->toContain('--runtime=runsc', '--cap-drop=ALL', '--security-opt=no-new-privileges')
        ->toContain(resource_path('review/skill').':/work/.review/skill:ro')
        ->and(implode(' ', $review['command']))->not->toContain('sk-ant-one');

    // Nothing of the repository stays behind.
    expect(File::isDirectory(storage_path('framework/testing/review-work/'.$this->repository->id.'-41')))->toBeFalse();
});

it('clones without a shallow history and fetches what the review compares before the reviewer starts', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    $steps = fakeSteps();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    $git = collect($steps)->filter(fn (array $step): bool => $step['command'][0] === 'git')->values();
    $clone = $git->first(fn (array $step): bool => $step['command'][1] === 'clone');

    expect($clone['command'])->toContain('--filter=blob:none', 'https://bitbucket.org/acme/shop.git')
        ->not->toContain('--depth')
        // The connection's token travels in the environment, never in the URL.
        ->and(implode(' ', $clone['command']))->not->toContain('bitbucket-token')
        ->and($clone['environment']['GIT_CONFIG_VALUE_0'])->toBe('Authorization: Basic '.base64_encode('x-token-auth:bitbucket-token'));

    $order = collect($steps)->map(fn (array $step): string => $step['command'][0] === 'git' ? 'git '.$step['command'][1] : (str_contains(end($step['command']), 'run-review.sh') ? 'review' : 'other'));

    expect($order->search('git fetch'))->toBeLessThan($order->search('review'))
        // Against the merge base, and against where the last review ended.
        ->and($git->filter(fn (array $step): bool => $step['command'][1] === 'diff')->count())->toBe(1)
        ->and($git->contains(fn (array $step): bool => $step['command'][1] === 'diff' && in_array(str_repeat('c', 40), $step['command'], true)))->toBeTrue();
});

it('shows the pull request that a review runs and how it ended', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    fakeSteps();

    // What the report step would have left behind.
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'build_number' => 41, 'status' => ReviewRun::STATUS_FINISHED, 'result' => 'FAILED',
        'findings' => [['id' => 'F1', 'status' => 'new']], 'started_at' => now(),
    ]);

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    $statuses = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), "/commit/{$this->sha}/statuses/build"));

    expect($statuses->map(fn (array $pair): string => $pair[0]['state'])->all())->toBe(['INPROGRESS', 'FAILED']);

    // Each leads to the run itself, by its uuid, not to the list of runs.
    expect(Str::isUuid($run->uuid))->toBeTrue()
        ->and($statuses->map(fn (array $pair): string => $pair[0]['url'])->unique()->all())
        ->toBe([url("/app/{$this->repository->tenant->slug}/review-runs/{$run->uuid}")]);
});

it('records on the run how long it waited for a worker', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    fakeSteps();

    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    // Queued 95 seconds before a worker picked it up.
    (new RunContainerReview($this->repository->id, 'feature/export', null, 41, time() - 95))->handle(app(ContainerReview::class));

    expect($run->refresh()->queue_seconds)->toBeGreaterThanOrEqual(95)->toBeLessThan(100);
});

it('runs no reviewer and shows nothing when the precheck says there is nothing to review', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    $steps = fakeSteps(needed: 'false');

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(stepRunning($steps, 'run-review.sh'))->toBeNull();
    Http::assertNothingSent();
});

it('fails the run when the precheck itself failed, instead of ending as though there was nothing to review', function () {
    ReviewLock::acquire($this->repository, 'feature/export', '41');

    $steps = fakeSteps(precheckExit: 1);

    expect(fn () => app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41))
        ->toThrow(ReviewStepFailed::class, 'The precheck could not open the review.');

    expect(stepRunning($steps, 'run-review.sh'))->toBeNull()
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(app(ContainerRunner::class)->isRunning($this->repository, '41'))->toBeFalse();
});

it('files no report for a reviewer that failed, releases the lock and says the review stopped', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    ReviewLock::acquire($this->repository, 'feature/export', '41');
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    $steps = fakeSteps(reviewerExit: 1);

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(reports($steps))->toBe([])
        ->and(ReviewLock::heldBy($this->repository, 'feature/export'))->toBeNull()
        ->and(ReviewRun::query()->sole()->status)->toBe(ReviewRun::STATUS_FAILED)
        ->and(app(ContainerRunner::class)->isRunning($this->repository, '41'))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/statuses/build') && $request['state'] === 'STOPPED');
});

it('runs a repository on its own runner, else the platform\'s, and offers the runners that are set up', function () {
    config(['review.cloudflare.local' => false]);

    expect(app(Runners::class)->default())->toBe('docker')
        ->and(app(Runners::class)->options())->toHaveKeys(['docker', 'diff'])->not->toHaveKey('cloudflare')
        // Nothing chosen, or a runner that is gone, follows the platform.
        ->and(Repository::factory()->create(['review_runner' => null])->runnerName())->toBe('docker')
        ->and(Repository::factory()->create(['review_runner' => 'pipeline'])->runnerName())->toBe('docker')
        ->and(Repository::factory()->create(['review_runner' => 'diff'])->runnerName())->toBe('diff');

    // Turned off, it is no longer offered, and the configured default gives way to
    // the first runner that is set up: a fresh installation reviews from the diff.
    config(['review.docker.enabled' => false]);

    expect(app(Runners::class)->options())->toHaveKeys(['diff'])->not->toHaveKey('docker')
        ->and(app(Runners::class)->default())->toBe('diff');

    // With nothing set up, the configured one stays, and the readiness says so.
    config(['review.diff.enabled' => false]);

    expect(app(Runners::class)->default())->toBe('docker');

    config(['review.diff.enabled' => true]);

    config(['review.default_runner' => 'diff']);

    expect(app(Runners::class)->default())->toBe('diff');

    // An administrator's pick goes over the configured one.
    app(PlatformSettings::class)->save(['runner' => 'cloudflare']);
    app()->forgetScopedInstances();

    expect(app(Runners::class)->default())->toBe('cloudflare')
        ->and(Repository::factory()->create(['review_runner' => null])->runner())->toBeInstanceOf(CloudflareRunner::class);
});

it('starts a review by hand on the repository\'s runner', function () {
    Queue::fake();

    $this->artisan('review:start', ['repository' => 'acme/shop', 'branch' => 'feature/export'])->assertSuccessful();
    $this->artisan('review:start', ['repository' => 'acme/unknown', 'branch' => 'feature/export'])->assertFailed();

    Queue::assertPushed(RunContainerReview::class, fn (RunContainerReview $job): bool => $job->branch === 'feature/export' && $job->commit === null);
});

it('queues a review on the connection made for long jobs when one is set', function () {
    Queue::fake();
    config(['review.container.connection' => 'reviews']);

    app(ContainerRunner::class)->start($this->repository, 'feature/export');

    Queue::connection('reviews')->assertPushedOn('reviews', RunContainerReview::class);
});

/**
 * The steps that ran a container, by the script they ran.
 *
 * @return array<int, array{command: array<int, string>, environment: array<string, mixed>}>
 */
function stepsRunning(ArrayObject $steps, string $script): array
{
    return array_values(array_filter((array) $steps, fn (array $step): bool => $step['command'][1] === 'run' && str_contains(end($step['command']), $script)));
}

/**
 * @return array<int, string> the git commands that ran, as their subcommand
 */
function gitCommands(ArrayObject $steps): array
{
    return array_values(array_map(fn (array $step): string => $step['command'][1], array_filter((array) $steps, fn (array $step): bool => $step['command'][0] === 'git')));
}

it('hands a review asked for on a branch to the job that is reviewing it, and starts a job otherwise', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    Queue::fake();
    $runner = app(ContainerRunner::class);

    $first = $runner->start($this->repository, 'feature/export');
    Queue::assertPushed(RunContainerReview::class, 1);

    $runner->claimBranch($this->repository, 'feature/export');
    $second = $runner->start($this->repository, 'feature/export', str_repeat('b', 40));
    $third = $runner->start($this->repository, 'feature/export', str_repeat('c', 40));
    $elsewhere = $runner->start($this->repository, 'feature/other');

    // Only the other branch got a job; the two on the claimed branch wait for its job.
    Queue::assertPushed(RunContainerReview::class, 2);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $elsewhere && $job->branch === 'feature/other');

    // The newest of them is the one to carry on with; the one before it is inside its diff.
    expect($runner->isRunning($this->repository, (string) $second))->toBeFalse()
        ->and($runner->isRunning($this->repository, (string) $third))->toBeTrue()
        ->and($runner->nextFollowUp($this->repository, 'feature/export'))->toMatchArray(['build' => $third, 'commit' => str_repeat('c', 40)])
        ->and($runner->nextFollowUp($this->repository, 'feature/export'))->toBeNull();

    // With nothing left the branch was let go, so the next review starts its own job.
    $runner->start($this->repository, 'feature/export');
    Queue::assertPushed(RunContainerReview::class, 3);

    expect($first)->toBeLessThan($second);
});

it('carries on with a new commit in the same checkout, after its own review was published', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();

    $asked = 0;
    $next = null;

    // A push arrives while the reviewer is busy, in each of the two reviews.
    $noted = null;
    $steps = fakeSteps(whileReviewing: function () use (&$asked, &$next, &$noted): void {
        // In the second review, the job has noted which run it carried on with.
        $noted = ++$asked === 2 ? [$next, app(ContainerRunner::class)->forgetRound($this->repository, 41)] : null;
        $next = app(ContainerRunner::class)->start($this->repository, 'feature/export', str_repeat($asked === 1 ? 'b' : 'c', 40));
    });

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, rounds: 2);

    expect($noted[1])->toBe(['build' => $noted[0], 'commit' => str_repeat('b', 40)]);

    $reviews = stepsRunning($steps, 'run-review.sh');
    $reports = array_values(array_filter((array) $steps, fn (array $step): bool => $step['command'] === ['worker', 'report']));
    $volumes = fn (array $step): array => array_values(array_filter($step['command'], fn (string $part): bool => str_contains($part, ':/')));

    // One clone; the second review fetched the branch and cleaned the tree instead.
    expect(gitCommands($steps))->toContain('clone', 'fetch', 'clean')
        ->and(array_count_values(gitCommands($steps))['clone'])->toBe(1)
        ->and($reviews)->toHaveCount(2)
        ->and($reports)->toHaveCount(2)
        // Each review reported before the next began.
        ->and(array_search($reports[0], (array) $steps, true))->toBeLessThan(array_search($reviews[1], (array) $steps, true))
        ->and($reviews[0]['environment'])->not->toHaveKey('REVIEW_CONTINUE')
        ->and($reviews[1]['environment']['REVIEW_CONTINUE'])->toBe('true')
        ->and($reviews[1]['environment']['BITBUCKET_BUILD_NUMBER'])->not->toBe('41')
        ->and($reviews[0]['environment']['HOME'])->toBe('/home/reviewer');

    // The reviewer keeps its home between the two and cannot write the git directory.
    $work = storage_path('framework/testing/review-work')."/{$this->repository->id}-41";

    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');

    // The home kept for the branch, which outlives the job.
    expect($volumes($reviews[1]))->toContain("{$home}:/home/reviewer", "{$work}/.git:/work/.git:ro")
        ->and(File::isDirectory($home))->toBeTrue();

    // The push during the second review is one too many: it gets a job of its own.
    Queue::assertPushed(RunContainerReview::class, 1);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $next && $job->commit === str_repeat('c', 40));

    expect(File::exists($work))->toBeFalse()
        ->and(File::exists("{$work}-home"))->toBeFalse();
});

it('starts a job for the commit that was waiting when the review before it did not finish', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 2]);
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();

    $next = null;
    $steps = fakeSteps(reviewerExit: 1, whileReviewing: function () use (&$next): void {
        $next = app(ContainerRunner::class)->start($this->repository, 'feature/export', str_repeat('b', 40));
    });

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, rounds: 3);

    // Nothing to carry on from: a reviewer that failed left no review and no session worth resuming.
    expect(stepsRunning($steps, 'run-review.sh'))->toHaveCount(1);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $next);
});

it('lets the branch go when the job died, so the next review is not handed to it', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();
    $runner = app(ContainerRunner::class);

    $runner->claimBranch($this->repository, 'feature/export');
    $waiting = $runner->start($this->repository, 'feature/export', str_repeat('b', 40));
    Queue::assertNothingPushed();

    app(ContainerReview::class)->abandon($this->repository, 'feature/export', $this->sha, 41, rounds: 2);

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $waiting);

    $runner->start($this->repository, 'feature/export');
    Queue::assertPushed(RunContainerReview::class, 2);
});

it('ends the review a job had carried on with when it died there, not the one it started as', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();
    $runner = app(ContainerRunner::class);
    $later = str_repeat('b', 40);

    $runner->markRunning($this->repository, 57);
    $runner->noteRound($this->repository, 41, 57, $later);

    app(ContainerReview::class)->abandon($this->repository, 'feature/export', $this->sha, 41, rounds: 2);

    expect($runner->isRunning($this->repository, '57'))->toBeFalse()
        ->and($runner->forgetRound($this->repository, 41))->toBeNull();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), "commit/{$later}/statuses/build") && $request['state'] === 'STOPPED');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), "commit/{$this->sha}/statuses/build"));
});

it('does no more reviews than the job was queued for, also when the setting was raised since', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();

    // Queued for one review; the tenant turned carrying on on while it waited.
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 2]);

    $next = null;
    $steps = fakeSteps(whileReviewing: function () use (&$next): void {
        $next = app(ContainerRunner::class)->start($this->repository, 'feature/export', str_repeat('b', 40));
    });

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, rounds: 1);

    expect(stepsRunning($steps, 'run-review.sh'))->toHaveCount(1);
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $next && $job->rounds === 3);
});

it('counts only its own tokens for a review that resumed the session of the one before it', function () {
    $home = storage_path('framework/testing/usage-home');
    File::deleteDirectory($home);
    File::ensureDirectoryExists("{$home}/.claude/projects/work");

    $message = fn (string $id, int $input): string => json_encode(['type' => 'assistant', 'message' => ['id' => $id, 'model' => 'claude-sonnet-5', 'usage' => ['input_tokens' => $input, 'output_tokens' => 10]]]);

    // The first review's message, and the one this review added to the same session.
    File::put("{$home}/.claude/projects/work/session.jsonl", implode("\n", [
        $message('msg_first', 1000),
        $message('msg_second', 200),
        json_encode(['type' => 'cost-state', 'totalCostUSD' => 1.5]),
    ])."\n");

    $collect = fn () => Process::path($home)->env(['HOME' => $home, 'CLAUDE_CONFIG_DIR' => ''])->run(['sh', resource_path('review/skill/collect-usage.sh')]);

    $collect();
    expect(File::json("{$home}/ai-usage.json"))->toBe(['usage' => ['claude-sonnet-5' => ['input' => 1200, 'output' => 20, 'cache_read' => 0, 'cache_creation' => 0]], 'cost' => 1.5]);

    // As run-claude.sh leaves it before resuming: the first review's message was counted.
    File::put("{$home}/.review-counted", "msg_first\n");

    $collect();
    expect(File::json("{$home}/ai-usage.json"))->toBe(['usage' => ['claude-sonnet-5' => ['input' => 200, 'output' => 10, 'cache_read' => 0, 'cache_creation' => 0]], 'cost' => null]);

    File::deleteDirectory($home);
});

it('keeps a trial off the pull request: a custom run of that pull request, with no status and no lock', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 2]);
    ReviewLock::acquire($this->repository, 'feature/export', '7');

    $steps = fakeSteps();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, rounds: 1, trial: new Trial(pullRequestId: 570, destination: 'main', model: 'sonnet'));

    foreach (['run-review.sh'] as $script) {
        expect(array_intersect_key(stepRunning($steps, $script)['environment'], array_flip(['BITBUCKET_PR_ID', 'REVIEW_CUSTOM_PROVIDER', 'REVIEW_CUSTOM', 'REVIEW_CUSTOM_MODEL'])))
            ->toBe(['BITBUCKET_PR_ID' => '570', 'REVIEW_CUSTOM_PROVIDER' => 'default', 'REVIEW_CUSTOM' => 'true', 'REVIEW_CUSTOM_MODEL' => 'sonnet']);
    }

    // Nothing was told to the pull request, and the lock of its real review is still that review's.
    Http::assertNothingSent();
    expect(ReviewLock::heldBy($this->repository, 'feature/export'))->toBe('7')
        // One review, whatever the repository carries on with: the reviewer has the clone to itself.
        ->and(stepsRunning($steps, 'run-review.sh'))->toHaveCount(1)
        ->and(implode(' ', stepRunning($steps, 'run-review.sh')['command']))->not->toContain('.git:ro');
});

it('reviews a merged pull request as it went in, against the destination as it was before', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    $steps = fakeSteps();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, trial: new Trial(pullRequestId: 570, destination: 'main', mergeCommit: str_repeat('d', 12)));

    $git = array_values(array_map(fn (array $step): array => array_slice($step['command'], 1), array_filter((array) $steps, fn (array $step): bool => $step['command'][0] === 'git')));

    // The destination is cloned, the branch that went in checked out, and the destination put back before the merge.
    expect($git[0])->toContain('clone', '--branch', 'main')
        ->and($git)->toContain(['rev-list', '--parents', '-n', '1', str_repeat('d', 12)])
        ->and($git)->toContain(['checkout', '--detach', str_repeat('f', 40)])
        ->and($git)->toContain(['update-ref', 'refs/remotes/origin/main', str_repeat('e', 40)])
        // Fetching the destination again would move it past the merge.
        ->and(gitCommands($steps))->not->toContain('fetch')
        ->and(stepRunning($steps, 'run-review.sh'))->not->toBeNull();
});

/**
 * A Cloudflare sandbox that notes what it was told to clone, and runs nothing.
 */
function cloningSandbox(bool $clonesItself = true): ArrayObject
{
    $told = new ArrayObject;

    app()->bind(Sandbox::class, fn () => new class($told, $clonesItself) extends CloudflareSandbox
    {
        public function __construct(protected ArrayObject $told, protected bool $clones) {}

        public function clonesItself(): bool
        {
            return $this->clones;
        }

        public function run(string $name, string $script, string $workDirectory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?Closure $output = null, ?string $composerCache = null, ?array $clone = null): ProcessResult
        {
            $this->told[] = $clone;

            return Process::result();
        }
    });

    return $told;
}

it('has the Cloudflare sandbox clone a private repository for a connection that asks for it, and clones nothing here', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    $this->repository->integration->update(['settings' => ['clone_in_sandbox' => true]]);
    $steps = fakeSteps();
    $told = cloningSandbox();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    // The commit is looked up on the host; the code stays there.
    expect(gitCommands($steps))->toBe(['ls-remote'])
        ->and($told[0])->toBe([
            'url' => 'https://bitbucket.org/acme/shop.git',
            'authorization' => 'Basic '.base64_encode('x-token-auth:bitbucket-token'),
            'ref' => 'refs/heads/feature/export',
            'name' => 'feature/export',
            'commit' => str_repeat('b', 40),
            'destination' => 'main',
            'base' => null,
            // Where the last review ended: what a follow-up compares with is fetched with the clone.
            'since' => str_repeat('c', 40),
        ]);
});

it('clones here for the Cloudflare sandbox when the connection does not ask otherwise, or the Worker cannot clone yet', function (bool $asks, bool $clonesItself) {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    $this->repository->integration->update(['settings' => ['clone_in_sandbox' => $asks]]);
    $steps = fakeSteps();
    $told = cloningSandbox($clonesItself);

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(gitCommands($steps))->toContain('clone')
        ->and($told[0])->toBeNull();
})->with([
    'the connection does not ask' => [false, true],
    'a Worker deployed before it could clone' => [true, false],
]);

it('has the Cloudflare sandbox clone a public repository without a credential, whatever its connection says', function () {
    Http::fake(['api.github.com/*' => Http::response([])]);
    $connection = Connection::factory()->gitHubSource()->create(['tenant_id' => $this->repository->tenant_id, 'credentials' => [], 'settings' => []]);
    $repository = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'shop']);
    $steps = fakeSteps();
    $told = cloningSandbox();

    app(ContainerReview::class)->run($repository, 'feature/export', $this->sha, 41);

    expect(gitCommands($steps))->toBe([])
        ->and($told[0])->toMatchArray(['url' => 'https://github.com/acme/shop.git', 'authorization' => null, 'commit' => $this->sha]);
});

it('clones a public repository of a git server here, which the sandbox may not reach, unless its connection asks otherwise', function () {
    $connection = Connection::factory()->create(['tenant_id' => $this->repository->tenant_id, 'driver' => 'git', 'credentials' => [], 'settings' => ['base_url' => 'https://git.example.com/acme']]);
    $repository = Repository::factory()->create(['connection_id' => $connection->id, 'slug' => 'shop']);
    $steps = fakeSteps();
    $told = cloningSandbox();

    app(ContainerReview::class)->run($repository, 'feature/export', $this->sha, 41);

    expect(gitCommands($steps))->toContain('clone')
        ->and($told[0])->toBeNull();
});

it('has the Cloudflare sandbox clone a merged pull request as it went in, from the parents its host gives the merge', function () {
    Http::fake(['api.bitbucket.org/*/commit/*' => Http::response([
        'hash' => str_repeat('d', 40),
        'message' => 'Merge feature/export',
        'parents' => [['hash' => str_repeat('e', 40)], ['hash' => str_repeat('f', 40)]],
    ]), 'api.bitbucket.org/*' => Http::response([])]);
    $this->repository->integration->update(['settings' => ['clone_in_sandbox' => true]]);
    $steps = fakeSteps();
    $told = cloningSandbox();

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, trial: new Trial(pullRequestId: 570, destination: 'main', mergeCommit: str_repeat('d', 12)));

    // The branch that went in, against the destination put back to before the merge.
    expect(gitCommands($steps))->toBe([])
        ->and($told[0])->toMatchArray(['ref' => 'refs/heads/main', 'name' => 'main', 'commit' => str_repeat('f', 40), 'destination' => 'main', 'base' => str_repeat('e', 40)]);
});

it('starts nothing from the webhook for a repository that only trials run on', function () {
    Queue::fake();
    $this->repository->update(['reviews_enabled' => false]);

    deliver('pullrequest:updated', pullRequestPayload())->assertOk();

    Queue::assertNothingPushed();
});

it('gives a job that may carry on the time of every review it may do', function () {
    config(['review.container.timeout' => 1800]);
    Queue::fake();
    $runner = app(ContainerRunner::class);

    // Nothing set: one review, and the time of one.
    $runner->start($this->repository, 'feature/export');
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->rounds === 1 && $job->timeout === 2400);
    expect($runner->lifetime($this->repository))->toBe(2700);

    // The tenant's default, then the repository's own number over it, held to the most a job may do.
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    expect(ReviewSettings::followUps($this->repository->refresh()))->toBe(1);

    ReviewSettings::saveFor($this->repository, ['follow_ups' => 9]);
    expect(ReviewSettings::followUps($this->repository->refresh()))->toBe(2)
        ->and($runner->lifetime($this->repository))->toBe(8100);

    $runner->start($this->repository, 'feature/other');
    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->rounds === 3 && $job->timeout === 7200);

    // The queue never hands a job out again while it may still be running.
    expect(7200)->toBeLessThan(config('horizon.defaults.reviews.timeout'))
        ->and(config('horizon.defaults.reviews.timeout'))->toBeLessThan(config('queue.connections.reviews.retry_after'));
});

it('gives a review more time than its reviewer, and the queue more than that', function () {
    $job = new RunContainerReview($this->repository->id, 'feature/export', null, 41);
    $supervisor = config('horizon.defaults.reviews');

    // In this order, or a review that is still running is handed out again.
    expect((int) config('review.container.timeout'))->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan($supervisor['timeout'])
        ->and($supervisor['timeout'])->toBeLessThan(config('queue.connections.reviews.retry_after'))
        ->and($job->tries)->toBe(1);
});

it('keeps what the steps print in the run\'s log as they print it, without the secrets they were given', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Connection::factory()->anthropic('sk-ant-one-secret')->create(['tenant_id' => $this->repository->tenant_id]);

    fakeSteps(reviewerExit: 3, reviewerPrints: "Reading the diff\n\e[32mUsing key sk-ant-one-secret\e[0m\nNo newline at the end");

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    $log = File::get(app(RunLog::class)->path($this->repository->id, 41));

    expect($log)
        ->toMatch('/^\d\d:\d\d:\d\d  ── precheck ──$/m')
        ->toMatch('/^\d\d:\d\d:\d\d  Reading the diff$/m')
        ->toContain('Using key [secret]')
        ->toContain('No newline at the end')
        ->toContain('The review step ended with exit code 3.')
        ->not->toContain('sk-ant-one-secret')
        ->not->toContain("\e[")
        // The reviewer failed, so nothing was reported.
        ->not->toContain('── report ──');
});

it('keeps no log when logs are turned off', function () {
    config(['review.container.log.enabled' => false]);
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Connection::factory()->anthropic('sk-ant-one')->create(['tenant_id' => $this->repository->tenant_id]);

    fakeSteps(reviewerPrints: "Reading the diff\n");

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(File::exists(app(RunLog::class)->path($this->repository->id, 41)))->toBeFalse();
});

it('keeps what Composer downloaded for a repository, for the reviewer of that repository only', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    $mount = function (ArrayObject $steps): ?string {
        $command = stepRunning($steps, 'run-review.sh')['command'];
        $volume = collect($command)->first(fn (string $part): bool => str_ends_with($part, ':/cache/composer'));

        return $volume === null ? null : Str::beforeLast($volume, ':');
    };

    $steps = fakeSteps();
    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    $cache = storage_path('framework/testing/review-cache/'.$this->repository->id);

    expect($mount($steps))->toBe($cache)
        ->and(stepRunning($steps, 'run-review.sh')['environment']['COMPOSER_CACHE_DIR'])->toBe('/cache/composer')
        // It outlives the review, unlike the checkout.
        ->and(File::isDirectory($cache))->toBeTrue()
        ->and(reports($steps))->toHaveCount(1);

    // Another repository never reads it: a pull request's code can write what the
    // next install runs.
    $other = app(ComposerCache::class)->directory(Repository::factory()->create(['slug' => 'another']));

    expect($other)->not->toBe($cache);

    config(['review.container.cache_directory' => '']);
    $steps = fakeSteps();
    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 42);

    expect($mount($steps))->toBeNull();
});

it('removes the Composer cache of a repository that had no review for a while', function () {
    $caches = app(ComposerCache::class);
    $old = $caches->directory(Repository::factory()->create(['slug' => 'archived']));
    $current = $caches->directory($this->repository);
    File::put("{$old}/files.zip", 'x');
    touch($old, now()->subDays(15)->getTimestamp());

    $this->artisan('review:prune-caches')->expectsOutput('Removed 1 cache(s).')->assertSuccessful();

    expect(File::isDirectory($old))->toBeFalse()
        ->and(File::isDirectory($current))->toBeTrue();
});

/**
 * The host of the repository with one open pull request from feature/export,
 * whose head is the commit that starts with the given letter.
 */
function fakeOpenPullRequest(string $head = 'a'): void
{
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests?*' => Http::response(['values' => [[
            'id' => 570, 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => str_repeat($head, 12)]],
        ]]]),
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*/statuses/build' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*' => fn (Request $request) => Http::response(['hash' => str_repeat(substr(basename(strtok($request->url(), '?')), 0, 1), 40)]),
    ]);
}

/**
 * @return array<int, array{string, string, string}> the commit, the state and the description of each build status sent
 */
function buildStatuses(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/statuses/build'))
        ->map(fn (array $pair): array => [substr(basename(dirname($pair[0]->url(), 2)), 0, 1), $pair[0]['state'], $pair[0]['description']])
        ->values()->all();
}

it('shows a review as queued from the moment it is started, on the pull request and in the panel', function () {
    Queue::fake();
    fakeOpenPullRequest();

    $build = app(ContainerRunner::class)->start($this->repository, 'feature/export');

    $run = ReviewRun::query()->sole();

    expect($run->only(['status', 'commit', 'build_number', 'pull_request_id', 'branch', 'runner']))->toBe([
        'status' => ReviewRun::STATUS_QUEUED, 'commit' => $this->sha, 'build_number' => $build, 'pull_request_id' => 570, 'branch' => 'feature/export', 'runner' => 'docker',
    ])->and(buildStatuses())->toBe([['a', 'INPROGRESS', 'Waiting for a worker']]);

    // The same push delivered again finds a review of that head on its way.
    $this->repository->integration->update(['webhook_key' => 'hook-one', 'webhook_secret' => 'secret']);
    deliver('pullrequest:updated', pullRequestPayload(['updated_on' => '2026-11-18T10:10:00+00:00']))->assertOk();

    Queue::assertPushed(RunContainerReview::class, 1);
});

it('gives the queued record to the review when it begins, and closes one that is told there is nothing to review', function () {
    fakeOpenPullRequest();
    Queue::fake();
    $build = app(ContainerRunner::class)->start($this->repository, 'feature/export');

    // The precheck opens the run under the commit it was cloned at.
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response([
            'id' => 570, 'title' => 'Add the export', 'description' => '', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $this->sha]],
            'destination' => ['branch' => ['name' => 'main']],
        ]),
    ]);
    $this->reviewing($this->repository)->refresh();
    $this->openReview(['pull_request_id' => 570, 'commit' => $this->sha, 'lock' => (string) $build])->assertOk();

    expect(ReviewRun::query()->sole()->only(['status', 'build_number']))->toBe(['status' => ReviewRun::STATUS_RUNNING, 'build_number' => $build]);

    // Another one, which its precheck turns down.
    $skipped = app(ContainerRunner::class)->start($this->repository, 'feature/other');
    fakeSteps(needed: 'false');

    app(ContainerReview::class)->run($this->repository, 'feature/other', null, $skipped);

    expect(ReviewRun::query()->where('build_number', $skipped)->sole()->status)->toBe(ReviewRun::STATUS_SKIPPED)
        ->and(collect(buildStatuses())->last())->toBe(['a', 'STOPPED', 'Nothing to review']);
});

it('says on the newer commit that its review waits for the one before it, and closes the one it overtakes', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    Queue::fake();
    $runner = app(ContainerRunner::class);

    fakeOpenPullRequest('a');
    $runner->start($this->repository, 'feature/export');
    $runner->claimBranch($this->repository, 'feature/export');

    $second = $runner->start($this->repository, 'feature/export', str_repeat('b', 40));
    $third = $runner->start($this->repository, 'feature/export', str_repeat('c', 40));

    // One job, and both newer commits say a review is coming.
    Queue::assertPushed(RunContainerReview::class, 1);

    expect(buildStatuses())->toBe([
        ['a', 'INPROGRESS', 'Waiting for a worker'],
        ['b', 'INPROGRESS', 'Waiting for the review of the commit before it'],
        ['c', 'INPROGRESS', 'Waiting for the review of the commit before it'],
        // The newest is the one the job carries on with.
        ['b', 'STOPPED', 'A newer commit is reviewed instead'],
    ])
        // Its page says which commit was reviewed in its place.
        ->and(ReviewRun::query()->where('build_number', $second)->sole()->only(['status', 'superseded_by']))->toBe(['status' => ReviewRun::STATUS_SKIPPED, 'superseded_by' => str_repeat('c', 40)])
        ->and(ReviewRun::query()->where('build_number', $third)->sole()->status)->toBe(ReviewRun::STATUS_QUEUED);
});

it('times out a queued run nothing ever began', function () {
    $run = fn (string $status, int $minutes): ReviewRun => ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop', 'branch' => 'feature/export',
        'commit' => $this->sha, 'build_number' => $minutes, 'status' => $status, 'started_at' => now()->subMinutes($minutes),
    ]);

    // It may wait behind a whole review, so longer than a running one may run.
    [$waiting, $lost] = [$run(ReviewRun::STATUS_QUEUED, 90), $run(ReviewRun::STATUS_QUEUED, 200)];

    $this->artisan('review:sweep-runs')->assertSuccessful();

    expect($waiting->refresh()->status)->toBe(ReviewRun::STATUS_QUEUED)
        ->and($lost->refresh()->status)->toBe(ReviewRun::STATUS_TIMEOUT);
});

it('gives a review it carried on with a job of its own when that review breaks before it begins', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['follow_ups' => 1]);
    fakeOpenPullRequest('b');
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    Queue::fake();

    $next = null;
    $steps = fakeSteps(whileReviewing: function () use (&$next): void {
        $next = app(ContainerRunner::class)->start($this->repository, 'feature/export');
    });

    // The branch cannot be fetched for the second review.
    $this->partialMock(Checkout::class)->shouldReceive('update')->andThrow(new CheckoutFailed('The branch could not be fetched.'));

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, rounds: 2);

    // Its record still says a review is coming, and a job from a fresh clone brings it.
    expect(ReviewRun::query()->where('build_number', $next)->sole()->status)->toBe(ReviewRun::STATUS_QUEUED)
        ->and(stepsRunning($steps, 'run-review.sh'))->toHaveCount(1);

    Queue::assertPushed(fn (RunContainerReview $job): bool => $job->build === $next && $job->branch === 'feature/export');
});

it('resumes the session of the last review of a branch from a fresh clone, a few times in a row', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    config(['review.container.sessions.resumes' => 2]);

    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    $continues = function (int $build): bool {
        $steps = fakeSteps();
        app(ContainerReview::class)->run($this->repository, 'feature/export', null, $build);
        $review = stepRunning($steps, 'run-review.sh');

        // The reviewer's home is the branch's, and the git directory is its to write: no job carries on here.
        expect(implode(' ', $review['command']))->toContain(':/home/reviewer')->not->toContain('/.git:/work/.git:ro');

        return ($review['environment']['REVIEW_CONTINUE'] ?? null) === 'true';
    };

    // Nothing to resume on the first review of the branch.
    expect($continues(41))->toBeFalse();

    // What run-claude.sh leaves behind: the session it ended in.
    File::put("{$home}/.review-session", "session-one\n");
    File::ensureDirectoryExists("{$home}/.claude/projects/work");
    File::put("{$home}/.claude/projects/work/session-one.jsonl", '{}');

    expect($continues(42))->toBeTrue()
        ->and($continues(43))->toBeTrue()
        // A session that followed that many commits is left behind, and its tools kept.
        ->and($continues(44))->toBeFalse()
        ->and(File::exists("{$home}/.review-session"))->toBeFalse()
        ->and(File::isDirectory("{$home}/.claude/projects"))->toBeFalse()
        ->and(File::isDirectory($home))->toBeTrue();
});

it('keeps a trial\'s session apart from the branch\'s, and forgets a branch\'s when its pull request is merged', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    $steps = fakeSteps();
    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41, trial: new Trial(pullRequestId: 570, destination: 'main'));

    // A home of its own, kept for questions about the trial: never the branch's.
    $trialHome = app(ReviewSessions::class)->home($this->repository, ReviewSessions::forTrial('feature/export', 41));
    $branchHome = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    $volumes = implode(' ', stepRunning($steps, 'run-review.sh')['command']);

    expect($volumes)->toContain("{$trialHome}:/home/reviewer")
        ->and($volumes)->not->toContain("{$branchHome}:/home/reviewer");

    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    File::put("{$home}/.review-session", "session-one\n");
    File::put("{$trialHome}/.review-session", "trial-session\n");

    // The trial is on record as a custom run of the branch, with its build.
    ReviewRun::query()->updateOrCreate(
        ['repository_id' => $this->repository->id, 'build_number' => 41],
        ['tenant_id' => $this->repository->tenant_id, 'workspace' => 'acme', 'slug' => $this->repository->slug, 'branch' => 'feature/export',
            'commit' => str_repeat('a', 40), 'custom' => true, 'kind' => 'pull-request', 'status' => ReviewRun::STATUS_UNPUBLISHED, 'started_at' => now()],
    );

    // And its archive on the sessions disk, as another worker would have it.
    config(['review.container.sessions.disk' => 'sessions']);
    Storage::fake('sessions');
    $archive = "review/sessions/{$this->repository->id}/".substr(hash('sha256', ReviewSessions::forTrial('feature/export', 41)), 0, 32).'.tar.gz';
    Storage::disk('sessions')->put($archive, 'archive');

    Queue::fake();
    deliver('pullrequest:fulfilled', pullRequestPayload(['state' => 'MERGED']))->assertOk();

    // Merged: the branch's session goes, and so do those of the trials on it.
    expect(File::isDirectory($home))->toBeFalse()
        ->and(File::isDirectory($trialHome))->toBeFalse();
    Storage::disk('sessions')->assertMissing($archive);
});

it('keeps a branch\'s home while a review of it still runs, when its pull request is merged', function () {
    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    File::put("{$home}/.review-session", "session-one\n");
    ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => $this->repository->slug,
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    app(ReviewSessions::class)->forget($this->repository, 'feature/export');

    // Its home is mounted in the running review; pruning takes it once unused.
    expect(File::get("{$home}/.review-session"))->toBe("session-one\n");
});

it('removes a session nobody used for a while', function () {
    $sessions = app(ReviewSessions::class);
    $old = $sessions->home($this->repository, 'feature/merged');
    $current = $sessions->home($this->repository, 'feature/export');
    touch($old, now()->subDays(15)->getTimestamp());

    $this->artisan('review:prune-caches')->expectsOutputToContain('Removed 1 session(s).')->assertSuccessful();

    expect(File::isDirectory($old))->toBeFalse()->and(File::isDirectory($current))->toBeTrue();
});

it('starts fresh after a review that resumed a session and failed, so one bad session fails one review', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    File::put("{$home}/.review-session", "session-one\n");

    $steps = fakeSteps(reviewerExit: 1);
    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect(stepRunning($steps, 'run-review.sh')['environment']['REVIEW_CONTINUE'])->toBe('true')
        ->and(File::exists("{$home}/.review-session"))->toBeFalse();

    $steps = fakeSteps();
    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 42);

    expect(stepRunning($steps, 'run-review.sh')['environment'])->not->toHaveKey('REVIEW_CONTINUE');
});

it('removes the links a step left in the reviewer\'s home, but not those of the CLIs in its caches', function () {
    $home = app(ReviewSessions::class)->home($this->repository, 'feature/export');
    $outside = storage_path('framework/testing/review-work/outside.txt');
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, 'the worker\'s own');

    Process::fake(function (PendingProcess $process) use ($home, $outside) {
        $command = (array) $process->command;

        // The step: the pull request's code leaves links in the home, beside the
        // CLI the reviewer installed.
        if ($command[1] === 'run') {
            File::ensureDirectoryExists("{$home}/.claude");
            File::ensureDirectoryExists("{$home}/.local/share/claude");
            File::ensureDirectoryExists("{$home}/.local/bin");
            File::put("{$home}/.local/share/claude/2.1.0", 'the CLI');
            symlink($outside, "{$home}/.review-resumes");
            symlink(dirname($outside), "{$home}/.claude/projects");
            symlink("{$home}/.local/share/claude/2.1.0", "{$home}/.local/bin/claude");
        }

        // find is the worker's own, and runs as it would.
        if ($command[0] === 'find') {
            (new Symfony\Component\Process\Process($command))->mustRun();
        }

        return Process::result();
    });

    app(Sandbox::class)->run('review-7-41-review', 'sh .review/skill/run-review.sh', storage_path('framework/testing/review-work/7-41'), [], trusted: false, timeout: 60, home: $home);

    expect(is_link("{$home}/.review-resumes"))->toBeFalse()
        ->and(is_link("{$home}/.claude/projects"))->toBeFalse()
        ->and(is_link("{$home}/.local/bin/claude"))->toBeTrue()
        ->and(File::get($outside))->toBe('the worker\'s own');
});

it('removes the links in a kept home before a review starts in it', function () {
    $sessions = app(ReviewSessions::class);
    $home = $sessions->home($this->repository, 'feature/export');
    $outside = storage_path('framework/testing/review-work/outside.txt');
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, 'the worker\'s own');
    symlink($outside, "{$home}/.review-session");

    $sessions->home($this->repository, 'feature/export');

    expect(is_link("{$home}/.review-session"))->toBeFalse()
        ->and(File::get($outside))->toBe('the worker\'s own');
});

it('counts a resumed session in a file of its own, never through a link in the home', function () {
    $sessions = app(ReviewSessions::class);
    $home = $sessions->home($this->repository, 'feature/export');
    $outside = storage_path('framework/testing/review-work/outside.txt');
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, 'the worker\'s own');
    symlink($outside, "{$home}/.review-resumes");

    $sessions->resumed($home);

    expect(File::get($outside))->toBe('the worker\'s own')
        ->and(is_link("{$home}/.review-resumes"))->toBeFalse()
        ->and(File::get("{$home}/.review-resumes"))->toBe('1');
});

it('copies a session for a question without what the links in it point to', function () {
    $sessions = app(ReviewSessions::class);
    $home = $sessions->home($this->repository, 'feature/export');
    $outside = storage_path('framework/testing/review-work/outside');
    File::ensureDirectoryExists($outside);
    File::put("{$outside}/secret.txt", 'the worker\'s own');
    File::put("{$home}/.review-session", "session-one\n");
    File::ensureDirectoryExists("{$home}/.claude/projects/work");
    File::put("{$home}/.claude/projects/work/session-one.jsonl", '{}');
    symlink("{$outside}/secret.txt", "{$home}/.claude/projects/work/secret.jsonl");
    symlink($outside, "{$home}/.claude/elsewhere");

    $copy = $sessions->copy($this->repository, 'feature/export', storage_path('framework/testing/review-work/question-home'));

    expect(File::get("{$copy}/.claude/projects/work/session-one.jsonl"))->toBe('{}')
        ->and(file_exists("{$copy}/.claude/projects/work/secret.jsonl"))->toBeFalse()
        ->and(file_exists("{$copy}/.claude/elsewhere"))->toBeFalse();
});

it('forgets a session without removing what a linked .claude points to', function () {
    $sessions = app(ReviewSessions::class);
    $home = $sessions->home($this->repository, 'feature/export');
    $outside = storage_path('framework/testing/review-work/outside');
    File::ensureDirectoryExists("{$outside}/projects");
    File::put("{$outside}/projects/kept.txt", 'the worker\'s own');
    symlink($outside, "{$home}/.claude");

    $sessions->reset($home);

    expect(File::get("{$outside}/projects/kept.txt"))->toBe('the worker\'s own');
});

it('keeps what the reviewer\'s container was seen to use on the run, with the limit it had', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    config(['review.container.memory' => '3g']);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    fakeSteps();
    // What the sandbox saw while the reviewer ran.
    $this->app->bind(Sandbox::class, fn () => new class extends Sandbox
    {
        public function run(string $name, string $script, string $workDirectory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?Closure $output = null, ?string $composerCache = null, ?array $clone = null): ProcessResult
        {
            $this->peakMemory = (int) (1.5 * 1024 ** 3);

            return Process::result();
        }
    });

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect($run->refresh()->only(['peak_memory_mb', 'memory_limit']))->toBe(['peak_memory_mb' => 1536, 'memory_limit' => '3g']);
});

it('keeps where the reviewer ran and what that cost on the run, when its sandbox is paid for by the second', function () {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);
    config(['review.cloudflare.instance' => 'basic']);
    $run = ReviewRun::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => $this->sha, 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    fakeSteps();
    $this->app->bind(Sandbox::class, fn () => new class extends Sandbox
    {
        public function name(): string
        {
            return 'cloudflare';
        }

        public function run(string $name, string $script, string $workDirectory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?Closure $output = null, ?string $composerCache = null, ?array $clone = null): ProcessResult
        {
            $this->seconds = 200;
            $this->cost = 0.0016;

            return Process::result();
        }
    });

    app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41);

    expect($run->refresh()->only(['sandbox', 'sandbox_size', 'sandbox_seconds', 'sandbox_cost']))
        ->toBe(['sandbox' => 'cloudflare', 'sandbox_size' => 'basic', 'sandbox_seconds' => 200, 'sandbox_cost' => '0.0016'])
        // Docker on the worker costs nothing by the run, and says nothing here.
        ->and($run->peak_memory_mb)->toBeNull();
});
