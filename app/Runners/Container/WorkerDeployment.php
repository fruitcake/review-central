<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Services\PlatformSettings;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Deploys the Cloudflare Worker that runs the sandboxes, from this installation's
 * own copy of it, with Wrangler: its packages, the bucket when it is not there,
 * the Worker, and the token this installation asks it with. What it answers at
 * and that token are kept under Settings, where the configuration reads them.
 *
 * Needs Node 20 or later on the machine that runs it, and an API token of the
 * account that may edit its Workers, containers and R2.
 */
class WorkerDeployment
{
    /** How the last deploy went, for the setup page. */
    public const RESULT = 'cloudflare-worker:deployed';

    /** Set while one is going. */
    public const DEPLOYING = 'cloudflare-worker:deploying';

    /** The configuration this deploy writes beside wrangler.jsonc, with this installation's names in it. */
    public const CONFIG = 'wrangler.deploy.json';

    /** Seconds one Wrangler or npm command may take. */
    protected const TIMEOUT = 900;

    /** Lines of output kept with the result. */
    protected const LOG_LINES = 200;

    /**
     * Whether a deploy can be asked for: an account and a token to do it with.
     */
    public static function ready(): bool
    {
        return filled(config('review.cloudflare.account')) && filled(config('review.cloudflare.api_token'));
    }

    public static function directory(): string
    {
        return base_path('cloudflare/worker');
    }

    /**
     * Deploys, and keeps how that went for the setup page.
     *
     * @param  (Closure(string): void)|null  $say  told each line of what happens, as it happens
     * @return array{ok: bool, url: string|null, error: string|null}
     */
    public function deploy(?Closure $say = null): array
    {
        $log = [];
        $tell = function (string $line) use (&$log, $say): void {
            $log[] = $line;
            $say !== null && $say($line);
        };

        Cache::put(self::DEPLOYING, now()->getTimestamp(), now()->addMinutes(30));

        try {
            $url = $this->steps($tell);
            $result = ['ok' => true, 'url' => $url, 'error' => null];
        } catch (Throwable $exception) {
            $tell($exception->getMessage());
            $result = ['ok' => false, 'url' => null, 'error' => $exception->getMessage()];
        } finally {
            Cache::forget(self::DEPLOYING);
        }

        Cache::put(self::RESULT, $result + [
            'at' => now()->getTimestamp(),
            'version' => CloudflareSandbox::workerVersion(),
            'log' => implode("\n", array_slice($log, -self::LOG_LINES)),
        ], now()->addMonth());
        // The setup page asks the Worker again, and counts what is wrong again.
        Cache::forget('setup-check:wrong');

        return $result;
    }

    /**
     * The Worker's configuration as this installation deploys it: wrangler.jsonc
     * with the Worker's name and the bucket's set to the ones configured here.
     *
     * @return array<string, mixed>
     */
    public static function configuration(string $worker, string $bucket): array
    {
        $source = File::get(self::directory().'/wrangler.jsonc');
        // Its comments stand on lines of their own.
        $configuration = json_decode((string) preg_replace('#^\s*//.*$#m', '', $source), true);

        if (! is_array($configuration)) {
            throw new RuntimeException('cloudflare/worker/wrangler.jsonc could not be read as JSON once its comments were taken out.');
        }

        $configuration['name'] = $worker;
        $configuration['r2_buckets'] = array_map(
            fn (array $binding): array => ($binding['binding'] ?? null) === 'BUCKET' ? ['bucket_name' => $bucket] + $binding : $binding,
            (array) ($configuration['r2_buckets'] ?? []),
        );

        return $configuration;
    }

    /**
     * @param  Closure(string): void  $tell
     * @return string where the Worker answers
     */
    protected function steps(Closure $tell): string
    {
        if (! self::ready()) {
            throw new RuntimeException('There is no Cloudflare account and API token to deploy with: set them under Settings, or CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN.');
        }

        $worker = (string) (config('review.cloudflare.worker') ?: PlatformSettings::DEFAULT_WORKER);
        $bucket = (string) (config('filesystems.disks.'.config('review.cloudflare.disk').'.bucket') ?: PlatformSettings::DEFAULT_BUCKET);

        $node = trim($this->run(['node', '--version'], $tell, 'find Node'));

        if (preg_match('/^v(\d+)/', $node, $match) !== 1 || (int) $match[1] < 20) {
            throw new RuntimeException("Wrangler needs Node 20 or later; this machine has {$node}.");
        }

        $this->install($tell);

        File::put(self::directory().'/'.self::CONFIG, (string) json_encode(self::configuration($worker, $bucket), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $tell("── the bucket {$bucket} ──");
            $this->run(['npx', 'wrangler', 'r2', 'bucket', 'create', $bucket], $tell, 'make the bucket', tolerate: 'already exists');

            $tell("── the Worker {$worker} ──");
            $deployed = $this->run(['npx', 'wrangler', 'deploy', '--config', self::CONFIG], $tell, 'deploy the Worker');

            $url = preg_match('#https://[A-Za-z0-9.-]+\.workers\.dev#', $deployed, $address) === 1 ? $address[0] : config('review.cloudflare.url');

            if (blank($url)) {
                throw new RuntimeException('The Worker is deployed, but Wrangler named no workers.dev address for it. Set REVIEW_CLOUDFLARE_URL to the address it answers at.');
            }

            // The token this installation asks the Worker with: the one it has, or a
            // new one for a first deploy. Given each time, so the two never differ.
            $token = (string) (config('review.cloudflare.token') ?: bin2hex(random_bytes(32)));
            $tell('── its token ──');
            $this->run(['npx', 'wrangler', 'secret', 'put', 'API_TOKEN', '--config', self::CONFIG], $tell, 'give the Worker its token', input: $token);

            app(PlatformSettings::class)->save([
                PlatformSettings::CLOUDFLARE_URL => $url,
                PlatformSettings::CLOUDFLARE_TOKEN => $token,
            ]);
            app(PlatformSettings::class)->applyCloudflare();
            $tell("Deployed: {$url}");

            return (string) $url;
        } finally {
            File::delete(self::directory().'/'.self::CONFIG);
        }
    }

    /**
     * Wrangler, as package-lock.json names it, installed once for each version of
     * that file.
     *
     * @param  Closure(string): void  $tell
     */
    protected function install(Closure $tell): void
    {
        $lock = hash_file('sha256', self::directory().'/package-lock.json') ?: '';
        $marker = self::directory().'/node_modules/.review-central-lock';

        if (File::exists(self::directory().'/node_modules/.bin/wrangler') && @file_get_contents($marker) === $lock) {
            return;
        }

        $tell('── its packages ──');
        $this->run(['npm', 'ci', '--no-audit', '--no-fund'], $tell, 'install Wrangler');
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, $lock);
    }

    /**
     * @param  list<string>  $command
     * @param  Closure(string): void  $tell
     * @param  string|null  $tolerate  output that means it was done before, which is no failure
     * @return string what it printed
     */
    protected function run(array $command, Closure $tell, string $what, ?string $tolerate = null, ?string $input = null): string
    {
        $process = Process::path(self::directory())
            ->timeout(self::TIMEOUT)
            ->env([
                'CLOUDFLARE_ACCOUNT_ID' => (string) config('review.cloudflare.account'),
                'CLOUDFLARE_API_TOKEN' => (string) config('review.cloudflare.api_token'),
                'CI' => 'true',
                'WRANGLER_SEND_METRICS' => 'false',
                'NO_UPDATE_NOTIFIER' => '1',
            ]);

        if ($input !== null) {
            $process = $process->input($input);
        }

        $buffer = '';
        $streamed = false;
        $result = $process->run($command, function (string $type, string $output) use (&$buffer, &$streamed, $tell): void {
            $streamed = true;
            $buffer .= $output;

            while (($end = strpos($buffer, "\n")) !== false) {
                $tell(rtrim(substr($buffer, 0, $end), "\r"));
                $buffer = substr($buffer, $end + 1);
            }
        });

        if ($buffer !== '') {
            $tell($buffer);
        }

        $printed = $result->output().$result->errorOutput();

        // What was not handed over as it came is told now, so a failure says why.
        if (! $streamed) {
            foreach (preg_split('/\R/', rtrim($printed)) ?: [] as $line) {
                $line !== '' && $tell($line);
            }
        }

        if ($result->failed() && ($tolerate === null || ! str_contains($printed, $tolerate))) {
            throw new RuntimeException("Could not {$what} (exit {$result->exitCode()}).");
        }

        return $printed;
    }
}
