<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Llm\ProviderKeys;
use App\Services\PlatformSettings;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Runs a step of a review in a Cloudflare sandbox: a microVM of its own, started
 * by the Worker in cloudflare/worker and gone when the step ends.
 *
 * A sandbox shares no disk with this worker, so what Docker mounts travels through
 * a bucket here. The checkout, the review's scripts and the reviewer's home go up
 * as one archive; the reviewer's home and the files it wrote come back as another.
 * What the step prints is streamed, as Docker's is.
 *
 * For a connection that asks for it, the sandbox clones the repository itself, and
 * the archive holds only the review's own files: see Checkout::forSandbox().
 *
 * What differs from Docker, and is not made up for:
 * - The sandbox starts from Cloudflare's own image. The first review installs PHP,
 *   Composer and the reviewers' CLIs, and the Worker keeps a snapshot of that for
 *   the ones after it to start from.
 * - Composer's own cache does not travel. A repository's vendor/ does: its
 *   destination branch's, through the bucket, which a pull request then installs
 *   only its own changes on top of.
 * - The scripts are files in the sandbox, not a read-only mount. A reviewer could
 *   change them for its own run, and for nobody else's.
 * - How much memory the reviewer used is not known.
 */
class CloudflareSandbox extends Sandbox
{
    public const NAME = 'cloudflare';

    /** What a step that clones in the sandbox checks first: that the Worker did clone. */
    protected const CLONED = '{ [ -d .git ] || { echo "The sandbox has no checkout: deploy the Worker again, from Admin → Setup, to have it clone." >&2; exit 1; }; }';

    /** What a step may leave in the work directory that this worker reads afterwards. */
    protected const KEEP = [
        'ai-review.json', 'ai-usage.json', 'review-answer.json', 'review-scan.md',
        'claude-review.jsonl', 'codex-review.jsonl', 'pi-review.jsonl', 'ocr-review.json',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    /** The token the Worker on this machine is started with: cloudflare/worker/.dev.vars. */
    public const LOCAL_TOKEN = 'local';

    /**
     * Whether there is a Worker to ask: a deployed one, with a bucket to reach it
     * through, or the one on this machine.
     */
    public static function configured(): bool
    {
        return self::deployed() || self::local();
    }

    /**
     * Whether the Worker is the one on this machine, started by `wrangler dev`:
     * for trying a change to it, or a review in a sandbox, without deploying.
     */
    public static function local(): bool
    {
        return ! self::deployed() && (bool) config('review.cloudflare.local');
    }

    protected static function deployed(): bool
    {
        return filled(config('review.cloudflare.url')) && filled(config('review.cloudflare.token')) && filled(config('review.cloudflare.disk'));
    }

    public function run(string $name, string $script, string $workDirectory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?Closure $output = null, ?string $composerCache = null, ?array $clone = null): ProcessResult
    {
        $this->peakMemory = null;
        $this->seconds = null;
        $this->cost = null;
        $this->samples = [];

        $files = $this->files();
        $instance = self::instance();
        // What is paid for: the instance's share of a processor, and its memory.
        $this->processors = isset(self::INSTANCES[$instance]) ? (float) config("review.cloudflare.instances.{$instance}.vcpu") : null;
        $this->memoryMb = isset(self::INSTANCES[$instance]) ? (int) round((float) config("review.cloudflare.instances.{$instance}.memory_gib") * 1024) : null;
        // One sandbox per step, named for it and for this attempt: a second
        // delivery of the same job gets a sandbox and a place in the bucket of its
        // own, and cannot read or overwrite what the first one is still using.
        $run = preg_replace('/[^A-Za-z0-9._-]/', '-', $name).'-'.bin2hex(random_bytes(4));
        $input = "review/sandbox/{$run}/input.tar.gz";
        $kept = "review/sandbox/{$run}/output.tar.gz";
        $archive = rtrim($workDirectory, '/').'-sandbox.tar';

        try {
            $output?->__invoke('out', "##[phase] upload\n");
            $this->pack($archive, $workDirectory, $home);
            $stream = fopen("{$archive}.gz", 'r');

            if ($stream === false) {
                throw new RuntimeException('The checkout could not be packed for the sandbox.');
            }

            try {
                $files->put($input, $stream);
            } finally {
                is_resource($stream) && fclose($stream);
            }

            // On Cloudflare's own image the review brings what installs its tools.
            // The Worker runs it once and starts later sandboxes from a snapshot
            // of the result, until the script changes.
            $setup = self::setupScript();

            // A checkout the sandbox clones is not here to look in for a composer.json:
            // the script uses the cache only where there is one.
            $vendor = $composerCache === null ? null : self::vendorCache($composerCache, $workDirectory, cloned: $clone !== null);

            // The keys stay in the Worker, which puts them in for their placeholders
            // on the way to the providers' hosts.
            $keys = config('review.cloudflare.proxy_keys')
                ? ProviderKeys::proxied($environment)
                : ['environment' => $environment, 'credentials' => []];

            $exit = $this->ask($run, [
                'input' => $input,
                'output' => $kept,
                'setup' => $setup,
                'setupVersion' => $setup === null ? null : self::setupVersion($setup),
                // A Worker deployed before it could clone would run the script on
                // no checkout at all.
                'script' => 'mkdir -p "$HOME" && '.($clone === null ? '' : self::CLONED.' && ').$script,
                'clone' => $clone,
                'env' => $keys['environment'] + ($vendor === null ? [] : ['COMPOSER_CACHE_DIR' => self::COMPOSER_CACHE, 'REVIEW_VENDOR_BASE' => $vendor['file']]),
                'credentials' => $keys['credentials'],
                'keep' => self::KEEP,
                'timeout' => $timeout,
                'instance' => $instance,
                'cache' => $vendor,
            ], $timeout, $output);

            if ($exit['kept']) {
                $output?->__invoke('out', "##[phase] download\n");
                $this->unpack($files, $kept, $workDirectory, $home);
            }

            $this->samples = $exit['samples'];
            $this->peakMemory = $this->samples === [] ? null : max(array_column($this->samples, 2)) * 1024 ** 2;

            $this->seconds = $exit['seconds'];
            $this->cost = self::estimate($instance, $exit['seconds']);

            return new SandboxResult("cloudflare sandbox {$run}", $exit['exitCode']);
        } finally {
            File::delete([$archive, "{$archive}.gz"]);
            rescue(fn () => $files->forget("review/sandbox/{$run}"), null, false);
            // A sandbox whose stream was cut is still running until it is told.
            rescue(fn () => $this->client(30)->delete("runs/{$run}"), null, false);
        }
    }

    /**
     * Where the repository's vendor/ is kept, in the sandbox and in the bucket:
     * one per repository, of its destination branch. The Worker puts it in place
     * before the step when the bucket has it; prepare-review.sh unpacks it and has
     * Composer install what the pull request changes, and writes a new one beside
     * it (`.new`) only when the lock is the destination branch's. The Worker puts
     * that in the bucket after the step.
     *
     * Under the repository's own name, as the cache on the worker is: the reviewer
     * runs the pull request's code and can write there.
     *
     * @param  bool  $cloned  whether the sandbox clones the checkout, so it is not in the work directory to look at
     * @return array{key: string, file: string}|null null for a checkout without a composer.json
     */
    public static function vendorCache(string $composerCache, string $workDirectory, bool $cloned = false): ?array
    {
        $json = rtrim($workDirectory, '/').'/composer.json';

        if (! $cloned && (! is_file($json) || is_link($json))) {
            return null;
        }

        $repository = preg_replace('/[^A-Za-z0-9_-]/', '-', basename(rtrim($composerCache, '/')));

        return ['key' => "review/cache/{$repository}/vendor-base.tar", 'file' => self::COMPOSER_CACHE.'/vendor-base.tar'];
    }

    /**
     * What installs the reviewers' tools on Cloudflare's own image, or null for an
     * image of one's own that has them.
     *
     * @param  array<string, string>|null  $versions  the CLIs' versions to install; those of the newest snapshot when left out
     */
    public static function setupScript(?array $versions = null): ?string
    {
        if (! config('review.cloudflare.setup')) {
            return null;
        }

        $script = (string) File::get(resource_path('review/skill/sandbox-setup.sh'));
        $versions ??= self::pinnedClis();

        // Each CLI at the version its snapshot has: a new release is a new setup,
        // made into a snapshot before any review starts from it.
        foreach (self::CLIS as $package) {
            $version = $versions[$package] ?? null;

            if (is_string($version) && preg_match('/^[0-9A-Za-z.+-]+$/', $version) === 1) {
                $script = (string) preg_replace('/(?<=\s)'.preg_quote($package, '/').'(?=\s)/', "\"{$package}@{$version}\"", $script);
            }
        }

        return $script;
    }

    /**
     * The reviewers' CLIs the setup installs at their newest release, by npm
     * package. The others it names keep the range written there.
     */
    public const CLIS = ['@anthropic-ai/claude-code', '@openai/codex'];

    /**
     * The versions the setup installs now: those of the newest snapshot.
     *
     * @return array<string, string>
     */
    public static function pinnedClis(): array
    {
        $pinned = app(PlatformSettings::class)->get(PlatformSettings::CLOUDFLARE_CLIS);

        return is_array($pinned) ? array_filter($pinned, 'is_string') : [];
    }

    /**
     * The newest release of each CLI, as npm names it; one npm does not answer
     * for is left out.
     *
     * @return array<string, string>
     */
    public static function latestClis(): array
    {
        $latest = [];

        foreach (self::CLIS as $package) {
            $version = rescue(fn (): mixed => Http::timeout(10)->connectTimeout(4)->acceptJson()->get('https://registry.npmjs.org/'.str_replace('/', '%2F', $package).'/latest')->throw()->json('version'), null, false);

            if (is_string($version) && preg_match('/^[0-9A-Za-z.+-]+$/', $version) === 1) {
                $latest[$package] = $version;
            }
        }

        return $latest;
    }

    /**
     * The version of the Worker's code as this installation has it, from the
     * source in cloudflare/worker: what a deployed Worker should say it is.
     */
    public static function workerVersion(): ?string
    {
        $source = @file_get_contents(base_path('cloudflare/worker/src/index.ts'));

        return is_string($source) && preg_match('/^const WORKER_VERSION = "([^"]+)";$/m', $source, $match) === 1 ? $match[1] : null;
    }

    /** What names a setup, and the snapshot of a sandbox that ran it. */
    public static function setupVersion(string $setup): string
    {
        return substr(hash('sha256', $setup), 0, 16);
    }

    /**
     * Has the Worker prepare the tools of the current setup ahead of the first
     * review: a sandbox of its own installs them and keeps a snapshot. Nothing is
     * done when the snapshot is there. Takes a few minutes the first time.
     *
     * @param  array<string, string>|null  $versions  the CLIs' versions to prepare; those of the newest snapshot when left out
     * @return array{ok: bool, made?: bool, seconds?: int, error?: string}
     */
    public function prepare(?array $versions = null): array
    {
        $setup = self::setupScript($versions);

        if ($setup === null) {
            return ['ok' => true, 'made' => false, 'seconds' => 0];
        }

        $response = $this->client(3600)->post('prepare', [
            'setup' => $setup,
            'setupVersion' => self::setupVersion($setup),
            'instance' => self::instance(),
        ]);

        $answer = (array) $response->json();

        return $response->successful()
            ? ['ok' => true, 'made' => (bool) ($answer['made'] ?? false), 'seconds' => (int) ($answer['seconds'] ?? 0)]
            : ['ok' => false, 'error' => (string) ($answer['error'] ?? "The Worker answered {$response->status()}.")];
    }

    /** The sizes a sandbox comes in, as Cloudflare names them, smallest first. */
    public const INSTANCES = ['lite' => 'lite', 'basic' => 'basic', 'standard-1' => 'standard-1', 'standard-2' => 'standard-2', 'standard-3' => 'standard-3', 'standard-4' => 'standard-4'];

    /** The default size, when neither the environment nor the settings pick one. */
    public const DEFAULT_INSTANCE = 'standard-1';

    /**
     * The size sandboxes run in: as the environment or the settings pick it.
     */
    public static function instance(): string
    {
        $instance = (string) config('review.cloudflare.instance');

        return isset(self::INSTANCES[$instance]) ? $instance : self::DEFAULT_INSTANCE;
    }

    /**
     * The Worker's samples as this application keeps them: when, processors busy,
     * megabytes in use, and the share of the time it waited for a processor. What
     * the Worker sends that is no sample is left out.
     *
     * @return list<array{0: int, 1: float, 2: int, 3: float}>
     */
    protected static function samples(mixed $samples): array
    {
        $kept = [];

        foreach (is_array($samples) ? $samples : [] as $sample) {
            if (is_array($sample) && count($sample) >= 3 && is_numeric($sample[0]) && is_numeric($sample[1]) && is_numeric($sample[2])) {
                $kept[] = [(int) $sample[0], round((float) $sample[1], 2), (int) $sample[2], round((float) ($sample[3] ?? 0), 2)];
            }
        }

        return $kept;
    }

    /**
     * What an instance of this size costs for this long, in dollars, at the rates
     * in the configuration. An estimate from above: memory and disk are charged
     * for the time an instance runs, a processor only for what it used, and this
     * counts all of it.
     */
    public static function estimate(string $instance, int $seconds): ?float
    {
        $size = config("review.cloudflare.instances.{$instance}");
        $rates = (array) config('review.cloudflare.rates');

        if (! is_array($size)) {
            return null;
        }

        return round($seconds * (
            (float) $size['vcpu'] * (float) $rates['vcpu_second']
            + (float) $size['memory_gib'] * (float) $rates['memory_gib_second']
            + (float) $size['disk_gb'] * (float) $rates['disk_gb_second']
        ), 4);
    }

    /**
     * One archive of what the sandbox starts with: the checkout at work/, this
     * application's scripts over whatever the checkout has in their place, and the
     * reviewer's home where one is kept.
     */
    protected function pack(string $archive, string $workDirectory, ?string $home): void
    {
        File::delete([$archive, "{$archive}.gz"]);

        // Names are moved under their place in the sandbox; where a link points is
        // left as it was written.
        $add = fn (string $mode, string $from, string $as, array $exclude = []) => Process::timeout(600)
            ->run(['tar', $mode, $archive, '--owner=0', '--group=0', ...$exclude, "--transform=flags=rh;s,^\\.,{$as},", '-C', $from, '.'])
            ->throw();

        $add('-cf', $workDirectory, 'work');
        $add('-rf', resource_path('review/skill'), 'work/.review/skill');

        if ($home !== null && is_dir($home)) {
            // The session alone: the CLIs and caches beside it are installed again
            // in the sandbox faster than they travel there and back.
            $add('-rf', $home, 'home/reviewer', ReviewSessions::excluded());
        }

        Process::timeout(600)->run(['gzip', '--fast', $archive])->throw();
    }

    /**
     * Asks the Worker for the run and hands on what the step prints as it comes.
     *
     * @param  array<string, mixed>  $spec
     * @param  Closure(string, string): void|null  $output
     * @return array{exitCode: int, seconds: int, kept: bool, samples: list<array{0: int, 1: float, 2: int, 3: float}>}
     */
    protected function ask(string $run, array $spec, int $timeout, ?Closure $output): array
    {
        // Its own time on top of the step's: the sandbox starts, and packs up after.
        $response = $this->client($timeout + 900)->withOptions(['stream' => true])->post("runs/{$run}", $spec);

        if (! $response->successful()) {
            throw new RuntimeException("The Cloudflare Worker answered {$response->status()}: ".mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300));
        }

        foreach ($this->events($response->toPsrResponse()->getBody()) as [$event, $data]) {
            if ($event === 'output' && is_string($data)) {
                $output?->__invoke('out', $data);
            } elseif ($event === 'note' && is_string($data)) {
                $output?->__invoke('out', $data."\n");
            } elseif ($event === 'error') {
                throw new RuntimeException('The Cloudflare sandbox did not run the step: '.mb_substr(is_string($data) ? $data : (string) json_encode($data), 0, 500));
            } elseif ($event === 'exit' && is_array($data)) {
                return ['exitCode' => (int) ($data['exitCode'] ?? 1), 'seconds' => (int) ($data['seconds'] ?? 0), 'kept' => ($data['kept'] ?? false) === true, 'samples' => self::samples($data['metrics']['samples'] ?? [])];
            }
        }

        throw new RuntimeException('The Cloudflare sandbox stopped answering before the step ended.');
    }

    /**
     * The events of the Worker's answer: a name and its data, decoded.
     *
     * @return iterable<int, array{string, mixed}>
     */
    protected function events(StreamInterface $body): iterable
    {
        $pending = '';
        $event = 'message';

        while (! $body->eof()) {
            $pending .= $body->read(8192);

            while (($end = strpos($pending, "\n")) !== false) {
                $line = rtrim(substr($pending, 0, $end), "\r");
                $pending = substr($pending, $end + 1);

                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    yield [$event, json_decode(trim(substr($line, 5)), true)];
                    $event = 'message';
                }
            }
        }
    }

    /**
     * Takes back what the reviewer left: the files this worker reads, and its home.
     *
     * The archive was made by a sandbox that ran the pull request's code, so it is
     * unpacked beside the work directory first and every link in it removed: only
     * plain files are then copied to where this worker reads them, and never onto
     * a link that was already there.
     */
    protected function unpack(SandboxFiles $files, string $key, string $workDirectory, ?string $home): void
    {
        $to = rtrim($workDirectory, '/').'-sandbox-out';
        $archive = "{$to}.tar.gz";

        try {
            File::deleteDirectory($to);
            File::ensureDirectoryExists($to, 0700);

            $stream = $files->get($key);

            if ($stream === null) {
                throw new RuntimeException('The Cloudflare sandbox kept nothing of the step.');
            }

            file_put_contents($archive, $stream);
            fclose($stream);

            Process::timeout(600)->run(['tar', '-xzf', $archive, '-C', $to, '--no-same-owner', '--no-same-permissions'])->throw();
            Process::timeout(120)->run(['find', $to, '-type', 'l', '-delete'])->throw();

            foreach (self::KEEP as $file) {
                $place = rtrim($workDirectory, '/')."/{$file}";

                // Whatever the checkout has under that name goes first. A pull
                // request can commit it as a link, and a copy onto a link writes
                // to where it points: a file of this worker's own.
                if (is_link($place) || is_file($place)) {
                    unlink($place);
                }

                if (is_file("{$to}/work/{$file}") && ! is_dir($place)) {
                    File::copy("{$to}/work/{$file}", $place);
                }
            }

            if ($home !== null && is_dir("{$to}/home/reviewer")) {
                // The same for a home kept from an earlier review: no link in it is followed.
                Process::timeout(120)->run(['find', $home, '-type', 'l', '-delete'])->throw();
                File::copyDirectory("{$to}/home/reviewer", $home);
            }
        } finally {
            File::delete($archive);
            File::deleteDirectory($to);
        }
    }

    /**
     * Where the archives travel: the bucket the deployed Worker is bound to, or,
     * for the Worker on this machine, the Worker itself. Its bucket is then a
     * stand-in that `wrangler dev` keeps, which nothing reaches but the Worker.
     */
    protected function files(): SandboxFiles
    {
        return self::local()
            ? new WorkerFiles(fn (int $timeout): PendingRequest => $this->client($timeout))
            : new BucketFiles(Storage::disk((string) config('review.cloudflare.disk')));
    }

    /** The first version of the Worker that clones a repository in the sandbox. */
    public const CLONES_SINCE = '2026-11-24.4';

    /**
     * Whether the Worker clones a repository in the sandbox. One deployed before it
     * could would run the reviewer on no checkout, so until it is deployed again
     * the worker clones. Asked of the Worker, and remembered for ten minutes.
     */
    public function clonesItself(): bool
    {
        $url = (string) (self::local() ? config('review.cloudflare.local_url') : config('review.cloudflare.url'));

        return Cache::remember('review.cloudflare.clones.'.md5($url), 600, function (): bool {
            $version = rescue(fn (): mixed => $this->client(10)->get('health')->json('version'), null, false);
            $comparable = fn (string $version): string => str_replace('-', '.', $version);

            return is_string($version) && version_compare($comparable($version), $comparable(self::CLONES_SINCE), '>=');
        });
    }

    protected function client(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim((string) (self::local() ? config('review.cloudflare.local_url') : config('review.cloudflare.url')), '/'))
            ->withToken((string) (self::local() ? self::LOCAL_TOKEN : config('review.cloudflare.token')))
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout($timeout);
    }
}
