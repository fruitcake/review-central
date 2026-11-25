<?php

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\ReviewSessions;
use App\Runners\Container\RunLog;
use App\Runners\Container\Sandbox;
use App\Runners\Container\Sandboxes;
use App\Runners\Container\Trial;
use App\Services\PlatformSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-work/7-41');
    // What a run that was cut short left behind is not this one's.
    File::deleteDirectory(storage_path('framework/testing/review-work'));
    File::deleteDirectory(storage_path('framework/testing/review-home'));
    File::ensureDirectoryExists($this->work);
    Storage::fake('r2');

    config(['review.cloudflare' => ['url' => 'https://sandbox.example.com', 'token' => 'worker-token', 'disk' => 'r2', 'instance' => 'basic', 'local' => false] + config('review.cloudflare')]);
});

// Not the directory itself: it is the repository's, with a file of its own.
afterEach(function () {
    foreach (['review-work', 'review-home', 'review-logs', 'review-sessions'] as $directory) {
        File::deleteDirectory(storage_path("framework/testing/{$directory}"));
    }

    File::delete(storage_path('framework/testing/review-home-own.txt'));
});

/**
 * The Worker's answer, as server-sent events.
 *
 * @param  array<int, array{string, mixed}>  $events
 */
function sandboxEvents(array $events): string
{
    // Numbered, as the Worker numbers them for a connection that is made again.
    return collect($events)->values()->map(fn (array $event, int $index): string => 'id: '.($index + 1)."\nevent: {$event[0]}\ndata: ".json_encode($event[1])."\n\n")->implode(": keep-alive\n\n");
}

it('runs a step in a Cloudflare sandbox: the checkout up through the bucket, the output streamed, the reviewer\'s files back', function () {
    $home = storage_path('framework/testing/review-home');
    File::ensureDirectoryExists($home);
    $asked = null;
    $uploaded = null;

    // tar and gzip are the worker's own; what they would have made is put there.
    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[0] === 'gzip') {
            File::put(end($command).'.gz', 'the checkout, packed');
        }

        // find is the worker's own too: the links in the home go, as it would remove them.
        if ($command[0] === 'find' && is_link($command[1].'/.review-session')) {
            unlink($command[1].'/.review-session');
        }

        if ($command[0] === 'tar' && $command[1] === '-xzf') {
            $to = $command[array_search('-C', $command, true) + 1];
            File::ensureDirectoryExists("{$to}/work");
            File::ensureDirectoryExists("{$to}/home/reviewer");
            File::put("{$to}/work/ai-review.json", '{"result":"PASSED"}');
            // Not one this worker reads: it stays in the sandbox's archive.
            File::put("{$to}/work/composer.json", '{}');
            File::put("{$to}/home/reviewer/.review-session", 'session-one');
        }

        return Process::result();
    });

    Http::fake(['sandbox.example.com/*' => function (Request $request) use (&$asked, &$uploaded) {
        if ($request->method() !== 'POST') {
            return Http::response(['destroyed' => true]);
        }

        $asked = $request->data();
        $uploaded = Storage::disk('r2')->get($asked['input']);
        Storage::disk('r2')->put($asked['output'], 'what the reviewer left');

        return Http::response(sandboxEvents([
            ['note', 'Sandbox started (basic).'],
            ['output', "Reviewing\npull "],
            ['output', "request 570\n"],
            ['exit', ['exitCode' => 0, 'seconds' => 200, 'kept' => true, 'metrics' => ['processors' => 1, 'memoryMb' => 1024, 'samples' => [[1_800_000_000, 0.2, 310, 0.0], [1_800_000_005, 0.25, 455, 0.4], 'not a sample']]]],
        ]), 200, ['Content-Type' => 'text/event-stream']);
    }]);

    // The pull request committed a name the worker copies back as a link to a
    // file of the worker's own, and left another in the reviewer's home.
    $own = storage_path('framework/testing/review-home-own.txt');
    File::put($own, 'the worker\'s own');
    symlink($own, "{$this->work}/ai-review.json");
    symlink($own, "{$home}/.review-session");

    $printed = '';
    $sandbox = app(CloudflareSandbox::class);
    $result = $sandbox->run('review-7-41-review', 'sh .review/skill/run-review.sh', $this->work, ['ANTHROPIC_API_KEY' => 'sk-ant-one'], trusted: false, timeout: 1800, home: $home, output: function (string $type, string $piece) use (&$printed) {
        $printed .= $piece;
    });

    expect($result->successful())->toBeTrue()
        // The steps on this side are marked for the run's metrics.
        ->and($printed)->toBe("##[phase] upload\nUploading the checkout: 20.0 B.\nSandbox started (basic).\nReviewing\npull request 570\n##[phase] download\n")
        ->and($uploaded)->toBe('the checkout, packed')
        ->and($asked['script'])->toBe('mkdir -p "$HOME" && sh .review/skill/run-review.sh')
        // On Cloudflare's own image the review brings what installs its tools, and
        // a name for the snapshot of a sandbox that ran it.
        ->and($asked['setup'])->toBe(File::get(resource_path('review/skill/sandbox-setup.sh')))
        ->and($asked['setupVersion'])->toBe(substr(hash_file('sha256', resource_path('review/skill/sandbox-setup.sh')), 0, 16))
        // The key stays in the Worker: the sandbox gets a placeholder, which the
        // Worker swaps for it on the way to Anthropic.
        ->and($asked['env'])->toHaveKey('ANTHROPIC_API_KEY')
        ->and($asked['env']['ANTHROPIC_API_KEY'])->toStartWith('sk-ant-placeholder-')
        ->and(json_encode($asked['env']))->not->toContain('sk-ant-one')
        ->and($asked['credentials'])->toBe([['host' => 'api.anthropic.com', 'placeholder' => $asked['env']['ANTHROPIC_API_KEY'], 'value' => 'sk-ant-one']])
        ->and($asked['timeout'])->toBe(1800)
        ->and($asked['instance'])->toBe('basic')
        ->and($asked['keep'])->toContain('ai-review.json', 'ai-usage.json')
        // Back on the worker: the report it reads, and the session for the next review.
        ->and(File::get("{$this->work}/ai-review.json"))->toBe('{"result":"PASSED"}')
        ->and(is_link("{$this->work}/ai-review.json"))->toBeFalse()
        // Written beside the link's target, never through it.
        ->and(File::get($own))->toBe('the worker\'s own')
        ->and(File::exists("{$this->work}/composer.json"))->toBeFalse()
        ->and(File::get("{$home}/.review-session"))->toBe('session-one')
        // 200 seconds of a quarter of a processor, 1 GiB and 4 GB of disk.
        ->and($sandbox->seconds)->toBe(200)
        ->and($sandbox->cost)->toBe(0.0016)
        ->and($sandbox->name())->toBe('cloudflare')
        // What it used, as the Worker sampled it inside, against what the size gives.
        ->and($sandbox->samples)->toBe([[1_800_000_000, 0.2, 310, 0.0], [1_800_000_005, 0.25, 455, 0.4]])
        ->and($sandbox->peakMemory)->toBe(455 * 1024 ** 2)
        ->and($sandbox->processors)->toBe(0.25)
        ->and($sandbox->memoryMb)->toBe(1024)
        // Nothing of the review stays in the bucket.
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_starts_with($request->url(), 'https://sandbox.example.com/runs/review-7-41-review-')
        && $request->hasHeader('Authorization', 'Bearer worker-token'));
    // Told to go, whether or not it had gone already.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_starts_with($request->url(), 'https://sandbox.example.com/runs/review-7-41-review-'));
    // The reviewer's home goes up as its session alone, without the CLIs and caches beside it.
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), ',home/reviewer,')
        && in_array('--exclude=./.local', (array) $process->command, true)
        && in_array('--exclude=./.npm', (array) $process->command, true));
    // Links in what came back are removed before anything is copied.
    Process::assertRan(fn ($process): bool => ((array) $process->command)[0] === 'find' && in_array('-delete', (array) $process->command, true));
});

it('fails the step when the sandbox did not run it, and leaves nothing behind', function (string $answer, int $status, string $message) {
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => fn (Request $request) => $request->method() === 'POST' ? Http::response($answer, $status) : Http::response([])]);

    expect(fn () => app(CloudflareSandbox::class)->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 60))
        ->toThrow(RuntimeException::class, $message);

    expect(Storage::disk('r2')->allFiles())->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
})->with([
    'the Worker refuses' => ['{"error":"Not allowed."}', 401, 'The Cloudflare Worker answered 401: Not allowed.'],
    'the sandbox does not start' => [sandboxEvents([['error', 'The sandbox did not start within a minute.']]), 200, 'did not run the step: The sandbox did not start within a minute.'],
    'the stream ends early' => [sandboxEvents([['output', 'Reviewing']]), 200, 'stopped answering before the step ended'],
]);

it('reports a step that failed in the sandbox by its exit code, as Docker does', function () {
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => Http::response(sandboxEvents([['exit', ['exitCode' => 124, 'seconds' => 1800, 'kept' => false]]]))]);

    $sandbox = app(CloudflareSandbox::class);
    $result = $sandbox->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 1800);

    // It ran, and it was paid for.
    expect($result->failed())->toBeTrue()
        ->and($result->exitCode())->toBe(124)
        ->and($sandbox->seconds)->toBe(1800);
});

it('tells the scripts the CLI versions its snapshot has, so they do not ask npm each review', function (bool $setup, array $told) {
    config(['review.cloudflare.setup' => $setup]);
    app(PlatformSettings::class)->save([PlatformSettings::CLOUDFLARE_CLIS => ['@anthropic-ai/claude-code' => '2.1.329', '@openai/codex' => '0.9.1; rm -rf /']]);
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => Http::response(sandboxEvents([['exit', ['exitCode' => 0, 'seconds' => 60, 'kept' => false]]]))]);

    app(CloudflareSandbox::class)->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 1800);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && array_intersect_key((array) $request['env'], ['REVIEW_CLAUDE_VERSION' => true, 'REVIEW_CODEX_VERSION' => true]) === $told);
})->with([
    'from the snapshot' => [true, ['REVIEW_CLAUDE_VERSION' => '2.1.329']],
    // An image of one's own has its own CLIs, which the scripts check with npm.
    'an image of one\'s own' => [false, []],
]);

it('runs in the sandbox of the trial\'s runner, else the repository\'s, else the platform\'s, and on Docker when Cloudflare is not set up', function () {
    $docker = new Sandbox;
    $trial = fn (?string $runner): Trial => new Trial(pullRequestId: 570, destination: 'main', sandbox: $runner);
    $repository = Repository::factory()->create(['review_runner' => null]);

    expect(app(Sandboxes::class)->for($trial('cloudflare'), $repository, $docker))->toBeInstanceOf(CloudflareSandbox::class)
        // The platform's runner: docker unless an administrator picked another.
        ->and(app(Sandboxes::class)->for($trial(null), $repository, $docker))->toBe($docker)
        ->and(app(Sandboxes::class)->for(null, $repository, $docker))->toBe($docker);

    app(PlatformSettings::class)->save(['runner' => 'cloudflare']);
    app()->forgetScopedInstances();

    expect(app(Sandboxes::class)->for(null, $repository, $docker))->toBeInstanceOf(CloudflareSandbox::class)
        // A repository's own runner over the platform's.
        ->and(app(Sandboxes::class)->for(null, tap($repository)->update(['review_runner' => 'docker']), $docker))->toBe($docker)
        // One that reviews from the diff has its trials and security reviews on the platform's.
        ->and(app(Sandboxes::class)->for(null, tap($repository)->update(['review_runner' => 'diff']), $docker))->toBeInstanceOf(CloudflareSandbox::class);

    config(['review.cloudflare.token' => null]);

    // Without a Worker to ask, Docker, whatever is asked for.
    expect(app(Sandboxes::class)->for($trial('cloudflare'), $repository, $docker))->toBe($docker);
});

it('copies a run\'s log to a disk, where a server that is not the worker reads it', function () {
    config(['review.container.log.disk' => 'r2', 'review.container.log.directory' => storage_path('framework/testing/review-logs')]);
    $repository = Repository::factory()->create();
    $run = ReviewRun::query()->create([
        'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'build_number' => 41, 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    $log = app(RunLog::class)->writer($repository->id, 41);
    $log->note('── review ──');
    $log->write('out', "Reviewing pull request 570\n");
    $log->flush();
    // The run's last line, with nothing after it to bring it along.
    $log->note('Filed: the review is on the pull request.');

    expect(Storage::disk('r2')->get("review/logs/{$repository->id}/41.log"))->toContain('── review ──', 'Reviewing pull request 570', 'Filed: the review is on the pull request.');

    // The worker's own file is gone, or was never on this server.
    File::deleteDirectory(storage_path('framework/testing/review-logs'));

    expect(app(RunLog::class)->tail($run)['text'])->toContain('Reviewing pull request 570');

    // And it goes with the others when it is old.
    $this->travel(15)->days();

    expect(app(RunLog::class)->prune())->toBe(1)
        ->and(Storage::disk('r2')->allFiles())->toBe([]);
});

it('keeps a session on a disk too, for a worker that has not got it', function () {
    config(['review.container.sessions.disk' => 'r2', 'review.container.sessions.directory' => storage_path('framework/testing/review-sessions')]);
    $repository = Repository::factory()->create();
    $sessions = app(ReviewSessions::class);

    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[0] === 'tar' && $command[1] === '-czf') {
            File::put($command[2], 'the session, packed');
        }

        if ($command[0] === 'tar' && $command[1] === '-xzf') {
            File::put($command[array_search('-C', $command, true) + 1].'/.review-session', 'session-one');
        }

        return Process::result();
    });

    $home = $sessions->home($repository, 'feature/export');
    File::put("{$home}/.review-session", 'session-one');
    $sessions->store($repository, 'feature/export');

    expect(Storage::disk('r2')->allFiles())->toHaveCount(1);
    // Kept as the session alone: the CLIs and caches stay on this worker.
    Process::assertRan(fn ($process): bool => ((array) $process->command)[1] === '-czf' && in_array('--exclude=./.local', (array) $process->command, true));

    // Another worker: nothing of the branch on its disk.
    File::deleteDirectory(storage_path('framework/testing/review-sessions'));

    expect($sessions->resumes($sessions->home($repository, 'feature/export')))->toBeTrue();

    // Merged or declined: gone from the disk as well.
    $sessions->forget($repository, 'feature/export');

    expect(Storage::disk('r2')->allFiles())->toBe([]);
});

it('talks to the Worker on this machine where none is deployed, and sends the files through it', function () {
    config(['review.cloudflare.url' => null, 'review.cloudflare.token' => null, 'review.cloudflare.local' => true]);
    $stored = [];

    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[0] === 'gzip') {
            File::put(end($command).'.gz', 'the checkout, packed');
        }

        if ($command[0] === 'tar' && $command[1] === '-xzf') {
            $to = $command[array_search('-C', $command, true) + 1];
            File::ensureDirectoryExists("{$to}/work");
            File::put("{$to}/work/ai-review.json", '{"result":"PASSED"}');
        }

        return Process::result();
    });

    Http::fake(['127.0.0.1:8787/*' => function (Request $request) use (&$stored) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_starts_with($path, '/files/')) {
            if ($request->method() === 'PUT') {
                $stored[$path] = $request->body();
            }

            return $request->method() === 'GET' ? Http::response('what the reviewer left') : Http::response(['ok' => true]);
        }

        return $request->method() === 'POST'
            ? Http::response(sandboxEvents([['exit', ['exitCode' => 0, 'seconds' => 60, 'kept' => true]]]))
            : Http::response([]);
    }]);

    expect(CloudflareSandbox::configured())->toBeTrue()->and(CloudflareSandbox::local())->toBeTrue();

    $result = app(CloudflareSandbox::class)->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 60);

    expect($result->successful())->toBeTrue()
        ->and(array_values($stored))->toBe(['the checkout, packed'])
        ->and(array_key_first($stored))->toMatch('~^/files/review/sandbox/review-7-41-review-[a-f0-9]{8}/input\.tar\.gz$~')
        ->and(File::get("{$this->work}/ai-review.json"))->toBe('{"result":"PASSED"}')
        // No bucket of ours was touched: the Worker keeps its own.
        ->and(Storage::disk('r2')->allFiles())->toBe([]);

    // With the token `wrangler dev` reads from .dev.vars.
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer local'));

    // A deployed Worker, once it is named, is the one that is asked.
    config(['review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token']);

    expect(CloudflareSandbox::local())->toBeFalse();
});

it('keeps a sandbox warm under the branch\'s name, and starts a fresh one of its own when that one is busy', function (bool $busy) {
    $asked = [];
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => function (Request $request) use (&$asked, $busy) {
        $asked[] = [$request->method(), str_replace('https://sandbox.example.com/', '', $request->url()), $request->method() === 'POST' ? ($request['warm'] ?? null) : null];

        return $busy && str_contains($request->url(), '/runs/warm-')
            ? Http::response(['error' => 'This sandbox is running a review.'], 409)
            : Http::response(sandboxEvents([['exit', ['exitCode' => 0, 'seconds' => 10, 'kept' => false, 'keptWarm' => str_contains($request->url(), '/runs/warm-')]]]), 200, ['Content-Type' => 'text/event-stream']);
    }]);
    $clone = ['url' => 'https://bitbucket.org/acme/shop.git', 'authorization' => null, 'ref' => 'refs/heads/feature', 'name' => 'feature', 'commit' => str_repeat('a', 40), 'destination' => 'main', 'base' => null, 'since' => null];

    $this->freezeTime();
    $sandbox = app(CloudflareSandbox::class);
    $sandbox->keepWarm('warm-7-abc', 900);
    $sandbox->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 1800, clone: $clone);

    expect($asked[0])->toBe(['POST', 'runs/warm-7-abc', ['seconds' => 900]])
        ->and($sandbox->keptWarm)->toBe(! $busy)
        ->and($sandbox->warmUntil?->timestamp)->toBe($busy ? null : now()->addSeconds(900)->timestamp);

    if ($busy) {
        // A sandbox of this run's own, not kept, and let go after it as always.
        expect($asked[1][0])->toBe('POST')->and($asked[1][1])->toStartWith('runs/review-7-41-review-')->and($asked[1][2])->toBeNull()
            ->and($asked[2])->toBe(['DELETE', $asked[1][1], null]);
    } else {
        // Left for the next review: nothing tells it to go.
        expect($asked)->toHaveCount(1);
    }

    // The next run is kept warm only when it is asked again.
    $asked = [];
    $sandbox->run('review-7-42-review', 'true', $this->work, [], trusted: false, timeout: 1800, clone: $clone);

    expect($asked[0][1])->toStartWith('runs/review-7-42-review-')->and($asked[0][2])->toBeNull();
})->with(['free' => false, 'busy' => true]);

it('has the Worker clone the repository and checks that it did before the step runs', function () {
    $asked = null;
    Process::fake(function ($process) {
        $command = (array) $process->command;

        if ($command[0] === 'gzip') {
            File::put(end($command).'.gz', 'the review\'s files, packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => function (Request $request) use (&$asked) {
        $asked ??= $request->data();

        return Http::response(sandboxEvents([['exit', ['exitCode' => 0, 'seconds' => 10, 'kept' => false]]]), 200, ['Content-Type' => 'text/event-stream']);
    }]);
    $clone = ['url' => 'https://bitbucket.org/acme/shop.git', 'authorization' => 'Basic dG9rZW4=', 'ref' => 'refs/heads/feature', 'name' => 'feature', 'commit' => str_repeat('a', 40), 'destination' => 'main', 'base' => null, 'since' => null];

    app(CloudflareSandbox::class)->run('review-7-41-review', 'sh .review/skill/run-review.sh', $this->work, [], trusted: false, timeout: 1800, composerCache: storage_path('app/review/cache/7'), clone: $clone);

    // An older Worker would run the step on no checkout; the step stops instead.
    expect($asked['clone'])->toBe($clone)
        ->and($asked['script'])->toStartWith('mkdir -p "$HOME" && { [ -d .git ] ||')
        ->and($asked['script'])->toEndWith('&& sh .review/skill/run-review.sh')
        // Whether there is a composer.json is for the script to see in the clone.
        ->and($asked['cache'])->toBe(['key' => 'review/cache/7/vendor-base.tar', 'file' => '/cache/composer/vendor-base.tar']);
});

it('knows from the Worker\'s version whether it clones in the sandbox, and asks again only after a while', function (?string $version, bool $clones) {
    Http::fake(['sandbox.example.com/health' => Http::response(['ok' => true, 'version' => $version])]);

    expect(app(CloudflareSandbox::class)->clonesItself())->toBe($clones)
        ->and(app(CloudflareSandbox::class)->clonesItself())->toBe($clones);

    Http::assertSentCount(1);
})->with([
    'this version' => [CloudflareSandbox::CLONES_SINCE, true],
    'a later one' => ['2026-12-01.1', true],
    'an earlier one' => ['2026-11-20.5', false],
    'none said' => [null, false],
]);

it('keeps one vendor/ per repository, of its destination branch, under the repository\'s name', function () {
    File::put("{$this->work}/composer.json", '{"require": {"laravel/framework": "^13.0"}}');

    $cache = CloudflareSandbox::vendorCache(storage_path('app/review/cache/7'), $this->work);

    expect($cache)->toBe(['key' => 'review/cache/7/vendor-base.tar', 'file' => '/cache/composer/vendor-base.tar'])
        // The Worker takes only names of these shapes.
        ->and(preg_match('/^review\/cache\/[A-Za-z0-9_-]+\/vendor-[a-z0-9-]+\.tar$/', $cache['key']))->toBe(1)
        ->and(preg_match('/^\/cache\/composer\/vendor-[a-z0-9-]+\.tar$/', $cache['file']))->toBe(1);

    // Nothing to install without a composer.json.
    File::delete("{$this->work}/composer.json");

    expect(CloudflareSandbox::vendorCache(storage_path('app/review/cache/7'), $this->work))->toBeNull();
});

it('keeps a sandbox after a review that asked for changes, for 15 minutes, unless the settings say otherwise', function () {
    config(['review.cloudflare.warm' => null, 'review.cloudflare.warm_minutes' => null]);

    expect(CloudflareSandbox::warmth())->toBe(['mode' => CloudflareSandbox::WARM_AFTER_CHANGES, 'seconds' => 900]);

    app(PlatformSettings::class)->save([PlatformSettings::CLOUDFLARE_WARM => CloudflareSandbox::WARM_ALWAYS, PlatformSettings::CLOUDFLARE_WARM_MINUTES => '500']);
    app(PlatformSettings::class)->applyCloudflare();

    // No longer than the Worker keeps one.
    expect(CloudflareSandbox::warmth())->toBe(['mode' => CloudflareSandbox::WARM_ALWAYS, 'seconds' => 3600]);
});

it('lets a warm sandbox go when its own run broke off, naming the attempt so no newer run is ended', function () {
    $asked = [];
    Process::fake(function ($process) {
        if (((array) $process->command)[0] === 'gzip') {
            File::put(end($process->command).'.gz', 'packed');
        }

        return Process::result();
    });
    Http::fake(['sandbox.example.com/*' => function (Request $request) use (&$asked) {
        $asked[] = [$request->method(), str_replace('https://sandbox.example.com/', '', $request->url()), $request->method() === 'POST' ? $request['attempt'] : null];

        // The stream ends before the step does.
        return Http::response(sandboxEvents([['output', 'Reviewing']]), 200, ['Content-Type' => 'text/event-stream']);
    }]);
    $clone = ['url' => 'https://bitbucket.org/acme/shop.git', 'authorization' => null, 'ref' => 'refs/heads/feature', 'name' => 'feature', 'commit' => str_repeat('a', 40), 'destination' => 'main', 'base' => null, 'since' => null];

    $sandbox = app(CloudflareSandbox::class);
    $sandbox->keepWarm('warm-7-abc', 900);

    expect(fn () => $sandbox->run('review-7-41-review', 'true', $this->work, [], trusted: false, timeout: 1800, clone: $clone))->toThrow(RuntimeException::class);

    expect($asked[0][2])->toStartWith('review-7-41-review-')
        ->and($asked[1])->toBe(['DELETE', "runs/warm-7-abc?idle=1&attempt={$asked[0][2]}", null]);
});
