<?php

use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Rules\PublicUrl;
use App\Runners\Container\ContainerReview;
use App\Runners\Container\Precheck;
use App\Runners\Container\RunLogWriter;
use App\Runners\Container\Sandbox;
use App\Runners\Container\Trial;
use App\Runners\ContainerRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['review.docker.enabled' => true, 'review.container.work_directory' => storage_path('framework/testing/review-work')]);

    $this->sha = str_repeat('a', 40);
    $this->repository = Repository::factory()->create(['slug' => 'shop']);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [], 'id' => 900]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/tasks*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/*' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response([
            'id' => 570, 'title' => 'Add the export', 'description' => '', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $this->sha]],
            'destination' => ['branch' => ['name' => 'main']],
        ]),
        'api.bitbucket.org/*' => Http::response([]),
    ]);
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/review-work')));

it('works out whether a review asks for changes from its findings, whatever the report calls itself', function () {
    $this->reviewing($this->repository);
    $file = fn (int $build, array $findings, string $result) => $this->fileReview([
        'branch' => 'feature/export', 'commit' => $this->sha, 'build' => $build, 'pull_request_id' => 570, 'result' => $result, 'summary' => 'Reviewed.', 'findings' => $findings,
    ])->assertOk();
    $finding = ['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'confidence' => 9, 'title' => 'Export shows other companies\' invoices', 'path' => 'app/Export.php', 'line' => 12];

    // A report that lists a finding that blocks and calls itself passed earns no approval.
    $file(41, [$finding], 'PASSED');

    expect(ReviewRun::query()->where('build_number', 41)->sole()->result)->toBe('FAILED');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/pullrequests/570/request-changes') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/pullrequests/570/approve') && $request->method() === 'POST');

    // And one that fails itself over a finding that blocks nothing holds no merge.
    $file(42, [['severity' => 'LOW'] + $finding], 'FAILED');

    expect(ReviewRun::query()->where('build_number', 42)->sole()->result)->toBe('PASSED');

    // The threshold is kept with the result it decided.
    expect(ReviewRun::query()->where('build_number', 41)->sole()->fail_on)->toBe('medium,security:any,tests:high,maintainability:never');
});

it('keeps what a failed step printed out of the application\'s log', function () {
    Log::spy();
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $this->app->bind(Precheck::class, fn () => new class extends Precheck
    {
        public function __construct() {}

        public function run(Repository $repository, string $branch, string $commit, int $build, string $directory, ?Trial $trial = null, ?RunLogWriter $log = null, string $runner = ContainerRunner::NAME): array
        {
            return ['REVIEW_NEEDED' => 'true', 'REVIEW_REVIEWER' => 'claude-code', 'REVIEW_PR_ID' => '570', 'REVIEW_DESTINATION_BRANCH' => 'main'];
        }
    });

    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[0] === 'git') {
            if ($command[1] === 'clone') {
                File::ensureDirectoryExists(end($command));
            }

            return Process::result(in_array('rev-parse', $command, true) ? str_repeat('a', 40)."\n" : '');
        }

        return Process::result(output: 'ANTHROPIC_API_KEY=sk-ant-leaked', exitCode: 1);
    });

    rescue(fn () => app(ContainerReview::class)->run($this->repository, 'feature/export', null, 41), report: false);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'review step')
        && $context['exit'] === 1 && ! str_contains(json_encode($context), 'sk-ant-leaked'));
});

it('takes an address only when it is https and on the public internet', function (string $url, bool $allowed) {
    expect(PublicUrl::allows($url))->toBe($allowed);
})->with([
    'a public address' => ['https://8.8.8.8/v1', true],
    'plain http' => ['http://8.8.8.8/v1', false],
    'this machine' => ['https://127.0.0.1:6379', false],
    'this machine, by name' => ['https://localhost/', false],
    'a private network' => ['https://10.0.0.5/', false],
    'the cloud\'s metadata service' => ['https://169.254.169.254/latest/meta-data/', false],
    'IPv6 loopback' => ['https://[::1]/', false],
    'no host' => ['https:///v1', false],
]);

it('keeps the most memory a running container was seen to use', function () {
    config(['review.container.sample_seconds' => 0]);
    $seen = ['512.3MiB / 4GiB', '1.25GiB / 4GiB', '800MiB / 4GiB'];

    Process::fake(function ($process) use (&$seen) {
        $command = (array) $process->command;

        return match ($command[1]) {
            // Still running for a few looks.
            'run' => Process::describe()->output('reviewing')->iterations(3),
            'stats' => Process::result(array_shift($seen) ?? ''),
            default => Process::result(),
        };
    });

    $sandbox = app(Sandbox::class);
    $sandbox->run('review-1-41-review', 'true', storage_path('framework/testing/review-work'), [], trusted: false, timeout: 60);

    expect($sandbox->peakMemory)->toBe((int) (1.25 * 1024 ** 3));

    // A step that ended before the first look was never seen.
    Process::fake(fn () => Process::result());
    $sandbox->run('review-1-42-review', 'true', storage_path('framework/testing/review-work'), [], trusted: false, timeout: 60);

    expect($sandbox->peakMemory)->toBeNull();
});

it('takes no reading from a runtime that reports nothing for a container', function () {
    config(['review.container.sample_seconds' => 0]);

    Process::fake(fn ($process) => match (((array) $process->command)[1]) {
        'run' => Process::describe()->output('reviewing')->iterations(2),
        'stats' => Process::result('0B / 0B'),
        default => Process::result(),
    });

    $sandbox = app(Sandbox::class);
    $sandbox->run('review-1-41-review', 'true', storage_path('framework/testing/review-work'), [], trusted: false, timeout: 60);

    // "Used 0 MB" would be a number someone sets a limit by.
    expect($sandbox->peakMemory)->toBeNull();
});
