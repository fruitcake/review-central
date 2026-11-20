<?php

use App\Filament\Admin\Pages\Setup;
use App\Jobs\CheckWorker;
use App\Jobs\PrepareCloudflareSandbox;
use App\Models\CapacitySample;
use App\Models\ReviewInstruction;
use App\Models\User;
use App\Runners\Container\CloudflareSandbox;
use App\Services\ReviewInstructions;
use App\Services\SetupCheck;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $root = storage_path('framework/testing/setup');

    config([
        'review.docker.enabled' => true, 'review.container.runtime' => 'runsc', 'review.container.image' => 'review-central/reviewer:latest',
        'review.container.work_directory' => "{$root}/work", 'review.container.cache_directory' => "{$root}/cache",
        'review.container.sessions.directory' => "{$root}/sessions", 'review.container.log.directory' => "{$root}/logs",
        'services.google.client_id' => null, 'services.google.client_secret' => null,
    ]);

    /** A check by its title, wherever it is. */
    $this->find = fn (string $title): ?array => collect(app(SetupCheck::class)->groups())
        ->flatMap(fn (array $group): array => $group['checks'])
        ->first(fn (array $check): bool => str_contains($check['title'], $title));

    /** Docker as the worker finds it. */
    $this->docker = fn (bool $installed = true, string $runtimes = '{"runc":{},"runsc":{}}', bool $image = true) => Process::fake(function ($process) use ($installed, $runtimes, $image) {
        $command = (array) $process->command;

        return match (true) {
            ! $installed => Process::result(errorOutput: 'permission denied while trying to connect to the Docker daemon socket', exitCode: 1),
            $command[1] === 'version' => Process::result('29.1.0'),
            $command[1] === 'info' => Process::result($runtimes),
            $command[1] === 'image' => Process::result(exitCode: $image ? 0 : 1),
            default => Process::result(),
        };
    });
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/setup')));

it('says what this server has in place, and what to do about what it has not', function () {
    config(['app.url' => 'http://review.example.com']);

    // No measurement of the server yet: the scheduler never ran.
    expect(($this->find)('The scheduler runs'))->toMatchArray(['state' => SetupCheck::WRONG])
        ->and(($this->find)('The scheduler runs')['fix'])->toContain('php artisan schedule:run')
        ->and(($this->find)('The address is https'))->toMatchArray(['state' => SetupCheck::WRONG])
        ->and(($this->find)('review instructions')['state'])->toBe(SetupCheck::NOTE);

    CapacitySample::query()->create(['sampled_at' => now()->subSeconds(30), 'running' => 0, 'waiting' => 0, 'held' => 0, 'workers' => 2]);
    ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY));
    config(['app.url' => 'https://review.example.com']);

    expect(($this->find)('The scheduler runs'))->toMatchArray(['state' => SetupCheck::OK, 'fix' => null])
        ->and(($this->find)('The address is https')['state'])->toBe(SetupCheck::OK)
        ->and(($this->find)('review instructions')['state'])->toBe(SetupCheck::OK);
});

it('asks the worker on the reviews\' own queue, and says so when nothing answers', function () {
    Queue::fake();

    expect(($this->find)('has not been asked')['state'])->toBe(SetupCheck::NOTE);

    app(SetupCheck::class)->askWorker();

    Queue::assertPushedOn('reviews', CheckWorker::class);
    // Just asked: it may still be on its way.
    expect(($this->find)('No worker has answered')['state'])->toBe(SetupCheck::NOTE);

    $this->travel(2)->minutes();

    expect(($this->find)('No worker has answered'))->toMatchArray(['state' => SetupCheck::WRONG])
        ->and(($this->find)('No worker has answered')['fix'])->toContain('php artisan horizon', '--queue=reviews');
});

it('reads the time it asked the worker as Redis gives it back: as a string', function () {
    Cache::put('setup-check:asked', (string) now()->subMinutes(5)->getTimestamp());

    expect(($this->find)('No worker has answered')['state'])->toBe(SetupCheck::WRONG);
});

it('names the user the worker runs as', function () {
    expect(SetupCheck::processUser())->toBe(function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user());
});

it('has the worker look at Docker, the runtime, the image and its directories', function () {
    ($this->docker)();

    (new CheckWorker)->handle(app(SetupCheck::class));

    expect(($this->find)('A worker listens')['state'])->toBe(SetupCheck::OK)
        ->and(($this->find)('Docker answers'))->toMatchArray(['state' => SetupCheck::OK, 'detail' => 'Docker 29.1.0.'])
        ->and(($this->find)('The runtime runsc')['state'])->toBe(SetupCheck::OK)
        ->and(($this->find)('image is built')['state'])->toBe(SetupCheck::OK)
        ->and(($this->find)('can write where a review writes')['state'])->toBe(SetupCheck::OK)
        ->and(app(SetupCheck::class)->wrong())->toBeGreaterThanOrEqual(0);

    // Asked again later, and this time nobody answers: the old answer is not taken for a new one.
    Queue::fake();
    $this->travel(5)->minutes();
    app(SetupCheck::class)->askWorker();
    $this->travel(2)->minutes();

    expect(($this->find)('stopped answering')['state'])->toBe(SetupCheck::WRONG);
});

it('tells what is missing on the worker, with the command that puts it there', function (array $docker, string $title, string $fix) {
    ($this->docker)(...$docker);

    app(SetupCheck::class)->recordWorker();

    expect(($this->find)($title))->toMatchArray(['state' => SetupCheck::WRONG])
        ->and(($this->find)($title)['fix'])->toContain($fix);
})->with([
    'no Docker for this user' => [['installed' => false], 'Docker answers', 'usermod -aG docker'],
    'no gVisor' => [['runtimes' => '{"runc":{}}'], 'The runtime runsc', 'runsc install'],
    'no image' => [['image' => false], 'image is built', 'docker build --pull -t review-central/reviewer:latest docker/reviewer'],
]);

it('says of what is optional whether it is on, off or half set', function () {
    expect(($this->find)('Signing in with Google'))->toMatchArray(['state' => SetupCheck::NOTE])
        ->and(($this->find)('Cloudflare sandboxes')['state'])->toBe(SetupCheck::NOTE);

    config(['services.google.client_id' => 'client-one', 'review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token']);
    // Every look at the page asks the Worker once: it answers three times, then it refuses the token.
    Http::fake(['sandbox.example.com/health*' => Http::sequence()->push(['ok' => true, 'bucket' => true])->push(['ok' => true, 'bucket' => true])->push(['ok' => true, 'bucket' => true])->push([], 401)]);

    // An id without its secret is not half on: it is off, and somebody meant it to be on.
    expect(($this->find)('Signing in with Google')['state'])->toBe(SetupCheck::WRONG)
        ->and(($this->find)('the Worker answers'))->toMatchArray(['state' => SetupCheck::OK])
        // The bucket is not set up yet, and says what to set.
        ->and(($this->find)('the bucket\'s keys'))->toMatchArray(['state' => SetupCheck::WRONG, 'detail' => 'Missing: R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_ENDPOINT.'])
        ->and(($this->find)('the Worker answers'))->toMatchArray(['state' => SetupCheck::WRONG, 'detail' => 'The Worker refuses the token.']);
});

it('gives every step to set up Cloudflare sandboxes while none of it is there', function () {
    config(['filesystems.disks.r2.key' => null, 'filesystems.disks.r2.secret' => null, 'filesystems.disks.r2.bucket' => null, 'filesystems.disks.r2.endpoint' => null]);

    expect(($this->find)('Cloudflare sandboxes'))->toMatchArray(['state' => SetupCheck::NOTE])
        ->and(($this->find)('Cloudflare sandboxes')['fix'])->toContain(
            'Workers Paid', 'npx wrangler r2 bucket create review-central', 'Manage API tokens',
            'npx wrangler secret put API_TOKEN', 'npx wrangler deploy', 'REVIEW_CLOUDFLARE_URL', 'R2_ENDPOINT', 'cd cloudflare/worker', 'not installed here',
        );
});

it('says when the Worker has no bucket, and when this installation cannot reach it', function () {
    config([
        'review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token',
        'filesystems.disks.r2.key' => 'key', 'filesystems.disks.r2.secret' => 'secret', 'filesystems.disks.r2.bucket' => 'review-central', 'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
    ]);
    Http::fake(['sandbox.example.com/health*' => Http::response(['ok' => true, 'bucket' => false])]);
    Storage::shouldReceive('disk')->with('r2')->andReturnSelf()->getMock()->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('review/setup-check', '', new RuntimeException('403 Forbidden')));

    expect(($this->find)('the Worker answers'))->toMatchArray(['state' => SetupCheck::WRONG, 'detail' => 'It answers, but has no bucket.'])
        ->and(($this->find)('reaches the bucket'))->toMatchArray(['state' => SetupCheck::WRONG, 'detail' => 'It cannot write to review-central: 403 Forbidden'])
        ->and(($this->find)('reaches the bucket')['fix'])->toContain('R2_ENDPOINT');
});

it('reaches the bucket as a review does, and says when its keys may write but not read', function () {
    config([
        'review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token',
        'filesystems.disks.r2.key' => 'key', 'filesystems.disks.r2.secret' => 'secret', 'filesystems.disks.r2.bucket' => 'review-central', 'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
    ]);
    Http::fake(['sandbox.example.com/health*' => Http::response(['ok' => true, 'bucket' => true])]);
    Storage::fake('r2');

    // Whether an object that is not there exists is never asked: without the right
    // to list the bucket, R2 answers that with 403.
    expect(($this->find)('reaches the bucket'))->toMatchArray(['state' => SetupCheck::OK, 'detail' => 'It can write to review-central, read from it and remove from it.'])
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    Storage::shouldReceive('disk')->with('r2')->andReturnSelf()->getMock()
        ->shouldReceive('put')->andReturnTrue()->getMock()
        ->shouldReceive('get')->andThrow(UnableToReadFile::fromLocation('review/setup-check', '403 Forbidden'));

    expect(($this->find)('reaches the bucket')['detail'])->toStartWith('It cannot read from review-central')
        ->and(($this->find)('reaches the bucket')['fix'])->toContain('Read as well as Edit');
});

it('says when the bucket\'s secret is not the one of its key ID', function () {
    config([
        'filesystems.disks.r2.key' => 'key', 'filesystems.disks.r2.secret' => 'secret', 'filesystems.disks.r2.bucket' => 'review-central', 'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
    ]);
    Storage::shouldReceive('disk')->with('r2')->andReturnSelf()->getMock()
        ->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('review/setup-check', '', new RuntimeException('AWS HTTP error: SignatureDoesNotMatch (client): The request signature we calculated does not match')));

    expect(($this->find)('reaches the bucket')['fix'])->toContain('Secret Access Key (64 characters)', 'not the token value');
});

it('shows the checks to an administrator, with a count of what is to fix in the menu', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Queue::fake();

    livewire(Setup::class)
        ->assertOk()
        ->assertSee('This server')
        ->assertSee('The scheduler runs')
        ->assertSee('php artisan schedule:run')
        ->callAction('askWorker');

    Queue::assertPushed(CheckWorker::class);

    expect(Setup::getNavigationBadge())->not->toBeNull();

    $this->actingAs(User::factory()->create());
    $this->get('/admin/setup')->assertForbidden();
});

it('says whether the sandbox\'s tools are prepared, and has the Worker prepare them', function () {
    config([
        'review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token', 'review.cloudflare.setup' => true,
        'filesystems.disks.r2.key' => null, 'filesystems.disks.r2.secret' => null, 'filesystems.disks.r2.bucket' => null, 'filesystems.disks.r2.endpoint' => null,
    ]);
    $version = CloudflareSandbox::setupVersion((string) CloudflareSandbox::setupScript());
    Http::fake([
        'sandbox.example.com/health*' => Http::sequence()->push(['ok' => true, 'bucket' => true, 'snapshot' => false])->push(['ok' => true, 'bucket' => true, 'snapshot' => false])->push(['ok' => true, 'bucket' => true, 'snapshot' => true]),
        'sandbox.example.com/prepare' => Http::response(['ok' => true, 'made' => true, 'seconds' => 230]),
    ]);

    expect(($this->find)('the tools are prepared'))->toMatchArray(['state' => SetupCheck::NOTE])
        ->and(($this->find)('the tools are prepared')['fix'])->toContain('Prepare the sandbox');

    PrepareCloudflareSandbox::ask();
    (new PrepareCloudflareSandbox)->handle(app(CloudflareSandbox::class));

    // Done, so no longer said to be going.
    expect(Cache::has(PrepareCloudflareSandbox::PREPARING))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.example.com/prepare' && $request['setupVersion'] === $version && $request['setup'] === CloudflareSandbox::setupScript());
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://sandbox.example.com/health?setup='.$version));

    expect(Cache::get(PrepareCloudflareSandbox::RESULT))->toMatchArray(['ok' => true, 'made' => true, 'version' => $version])
        ->and(($this->find)('the tools are prepared'))->toMatchArray(['state' => SetupCheck::OK]);
});

it('tells a Worker that was not deployed again after a change, and a prepare that is going', function () {
    config([
        'review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token', 'review.cloudflare.setup' => true,
        'filesystems.disks.r2.key' => null, 'filesystems.disks.r2.secret' => null, 'filesystems.disks.r2.bucket' => null, 'filesystems.disks.r2.endpoint' => null,
    ]);
    Queue::fake();
    Http::fake(['sandbox.example.com/health*' => Http::sequence()
        ->push(['ok' => true, 'bucket' => true, 'snapshot' => false])
        ->push(['ok' => true, 'bucket' => true, 'snapshot' => false])
        ->push(['ok' => true, 'version' => CloudflareSandbox::workerVersion(), 'bucket' => true, 'snapshot' => false])
        ->push(['ok' => true, 'version' => CloudflareSandbox::workerVersion(), 'bucket' => true, 'snapshot' => false]),
    ]);

    // Deployed before it said its version.
    expect(($this->find)('the Worker is up to date'))->toMatchArray(['state' => SetupCheck::WRONG])
        ->and(($this->find)('the Worker is up to date')['fix'])->toContain('npx wrangler deploy');

    PrepareCloudflareSandbox::ask();

    Queue::assertPushedOn('reviews', PrepareCloudflareSandbox::class);

    expect(($this->find)('the Worker is up to date'))->toMatchArray(['state' => SetupCheck::OK])
        ->and(($this->find)('the tools are prepared')['detail'])->toContain('Being prepared, since');
});

it('says when this release has newer review instructions than are kept here', function () {
    ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY));
    ReviewInstruction::query()->whereNull('tenant_id')->where('key', 'method')->update(['body' => 'Old method.', 'shipped_hash' => hash('sha256', 'Old method.')]);

    expect(($this->find)('newer review instructions'))->toMatchArray(['state' => SetupCheck::NOTE])
        ->and(($this->find)('newer review instructions')['fix'])->toContain('Update from this release');
});
