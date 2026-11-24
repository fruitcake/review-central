<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Exceptions\ReviewStopped;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs one step of a review in a container that is thrown away afterwards.
 *
 * Every step gets the work directory at /work and the review's scripts read-only
 * over it, straight from this application: a step that reads the pull request's
 * code can change neither the scripts a later step runs nor anything outside its
 * work directory.
 */
class Sandbox
{
    public const WORK = '/work';

    public const COMPOSER_CACHE = '/cache/composer';

    /**
     * The most memory the last step was seen to use, in bytes, or null when it was
     * never seen: it ended before the first look, or Docker would not say.
     */
    public ?int $peakMemory = null;

    /**
     * What the last step was seen to use, every few seconds: when, how many
     * processors' worth it kept busy, how many megabytes of memory it held, and
     * the share of the time it waited for a processor it was not given.
     *
     * @var list<array{0: int, 1: float, 2: int, 3: float}>
     */
    public array $samples = [];

    /** How many processors the last step could use, and how many megabytes of memory. */
    public ?float $processors = null;

    public ?int $memoryMb = null;

    /** How long the last step's sandbox ran, in seconds, where the sandbox is paid for by the second. */
    public ?int $seconds = null;

    /** What the last step's sandbox cost in dollars, as far as that can be estimated; null where it costs nothing by the run. */
    public ?float $cost = null;

    /**
     * Asked while the step runs: true stops it, as someone stopped the review.
     * Set for the step it is for, and cleared after.
     *
     * @var (Closure(): bool)|null
     */
    public ?Closure $stopWhen = null;

    /** Seconds between two looks at whether the review was stopped. */
    protected const STOP_CHECK_SECONDS = 2.0;

    /**
     * Where the steps run, as a review's record names it.
     */
    public function name(): string
    {
        return 'docker';
    }

    /**
     * @param  string  $name  the container's name, so it can be removed when the step is cut short
     * @param  array<string, string>  $environment
     * @param  bool  $trusted  a step that talks to this server and reads no code: it gets the server's network
     * @param  string|null  $home  a directory on the worker that outlives the step, as the step's home
     * @param  bool  $lockedGit  the clone's own git directory read-only, so the step cannot change what git does on the worker afterwards
     * @param  Closure(string, string): void|null  $output  called with each piece of what the step prints, as it prints it
     * @param  string|null  $composerCache  a directory on the worker that Composer keeps its downloads in, from one review to the next
     * @param  array{url: string, authorization: string|null, ref: string, name: string, commit: string, destination: string|null, base: string|null, since: string|null}|null  $clone  what the sandbox clones for itself (Checkout::forSandbox()): only a Cloudflare sandbox does, and Docker is never handed one
     */
    public function run(string $name, string $script, string $workDirectory, array $environment, bool $trusted, int $timeout, ?string $home = null, bool $lockedGit = false, ?Closure $output = null, ?string $composerCache = null, ?array $clone = null): ProcessResult
    {
        $config = config('review.container');

        $command = [
            $config['docker'], 'run', '--rm', '--name', $name,
            '--network', $trusted ? $config['server_network'] : $config['network'],
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--pids-limit='.$config['pids'],
            '--memory='.$config['memory'],
            '--cpus='.$config['cpus'],
            // As the worker's own user, so what the step writes can be removed again.
            '--user', posix_getuid().':'.posix_getgid(),
            '--tmpfs', '/tmp:exec,mode=1777',
            '--workdir', self::WORK,
            '--volume', $workDirectory.':'.self::WORK,
            '--volume', resource_path('review/skill').':'.self::WORK.'/.review/skill:ro',
        ];

        if ($lockedGit) {
            array_push($command, '--volume', $workDirectory.'/.git:'.self::WORK.'/.git:ro');
        }

        if ($home !== null) {
            array_push($command, '--volume', $home.':/home/reviewer');
        }

        if ($composerCache !== null) {
            array_push($command, '--volume', $composerCache.':'.self::COMPOSER_CACHE);
            $environment += ['COMPOSER_CACHE_DIR' => self::COMPOSER_CACHE];
        }

        if (filled($config['runtime'])) {
            $command[] = '--runtime='.$config['runtime'];
        }

        $environment += ['HOME' => $home === null ? '/tmp/home' : '/home/reviewer'];

        // Names only: the values travel in the docker client's environment, not
        // in a command line any process on the worker can read.
        foreach (array_keys($environment) as $variable) {
            array_push($command, '--env', $variable);
        }

        array_push($command, $config['image'], 'sh', '-c', 'mkdir -p "$HOME" && '.$script);

        $this->peakMemory = null;
        $this->samples = [];
        $this->processors = (float) $config['cpus'] ?: null;
        $this->memoryMb = self::megabytes((string) $config['memory']);

        try {
            // Started and waited for, which hands the output over as it comes.
            $process = Process::timeout($timeout)->env($environment)->start($command, $output);
            $deadline = microtime(true) + $timeout;
            $every = (float) $config['sample_seconds'];
            $next = microtime(true) + $every;
            $look = microtime(true);

            // While it runs, the container is asked now and then what it uses. Past
            // the time it may take, the wait below is what cuts it short.
            while ($process->running() && microtime(true) < $deadline) {
                usleep(250_000);

                if (microtime(true) >= $next) {
                    $this->sample($config['docker'], $name);
                    $next = microtime(true) + $every;
                }

                // Someone stopped the review: the container goes, in the finally below.
                if ($this->stopWhen !== null && microtime(true) >= $look) {
                    if (($this->stopWhen)()) {
                        $process->signal(SIGKILL);

                        throw new ReviewStopped("The {$name} step was stopped.");
                    }

                    $look = microtime(true) + self::STOP_CHECK_SECONDS;
                }
            }

            return $process->wait();
        } finally {
            // A step that timed out leaves its container running; one that ended is
            // already gone, and this says so without failing.
            Process::timeout(30)->run([$config['docker'], 'rm', '--force', $name]);

            // The step ran the pull request's code with the home mounted, and the
            // worker reads and writes the home after it.
            if ($home !== null) {
                ReviewSessions::removeLinks($home);
            }
        }
    }

    /**
     * Megabytes in Docker's way of writing a size: "4g", "512m", "2048".
     */
    public static function megabytes(string $size): ?int
    {
        if (preg_match('/^\s*([\d.]+)\s*([kmgt]?)b?\s*$/i', $size, $match) !== 1) {
            return null;
        }

        $factor = match (strtolower($match[2])) {
            'k' => 1 / 1024,
            'g' => 1024,
            't' => 1024 ** 2,
            'm' => 1,
            default => 1 / 1024 ** 2,
        };

        return (int) round((float) $match[1] * $factor) ?: null;
    }

    /**
     * Notes what the container uses now, when that is more than it was seen to use
     * before. A sample, so a short spike between two looks is missed: the peak is
     * at least this.
     */
    protected function sample(string $docker, string $name): void
    {
        $stats = rescue(fn (): string => Process::timeout(15)->run([$docker, 'stats', '--no-stream', '--format', '{{.CPUPerc}}|{{.MemUsage}}', $name])->output(), '', false);

        // "143.52%|512.3MiB / 4GiB": a processor's worth in percent, then what it
        // uses of its memory and the limit.
        if (preg_match('/^\s*(?<cpu>[\d.]+)%\|\s*(?<amount>[\d.]+)\s*(?<unit>[KMGT]?i?B)/i', $stats, $matches) !== 1) {
            return;
        }

        $bytes = (int) ((float) $matches['amount'] * match (strtoupper($matches['unit'][0])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
            default => 1,
        });

        // Nothing, as some runtimes answer for a container they cannot measure, is
        // not a measurement: a review that "used 0 MB" would mislead.
        if ($bytes > 0) {
            $this->peakMemory = max($this->peakMemory ?? 0, $bytes);
            $this->samples[] = [now()->getTimestamp(), round((float) $matches['cpu'] / 100, 2), (int) round($bytes / 1024 ** 2), 0.0];
        }
    }
}
