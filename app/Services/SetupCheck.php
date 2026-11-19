<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CheckWorker;
use App\Jobs\PrepareCloudflareSandbox;
use App\Models\CapacitySample;
use App\Models\ModelPrice;
use App\Models\ReviewInstruction;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Runners;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether this installation is set up to review: what is in place, what is not,
 * and what to do about each thing that is not.
 *
 * Half of it can be seen from here, the web server. The other half is the
 * worker's to say: whether it has Docker, the runtime and the image, and can
 * write where a review writes. That half is asked by a job on the reviews' own
 * queue, which also shows whether anything is listening to that queue at all.
 */
class SetupCheck
{
    public const OK = 'ok';

    /** Works, with something worth knowing or doing. */
    public const NOTE = 'note';

    public const WRONG = 'wrong';

    protected const WORKER = 'setup-check:worker';

    protected const ASKED = 'setup-check:asked';

    /** Seconds a worker gets to pick the check up before it is taken to be absent. */
    protected const PATIENCE = 60;

    /**
     * Asks the worker to look, by a job on the reviews' queue.
     */

    /**
     * The user this process runs as. get_current_user() is the owner of the
     * script, which on a server deployed by another user is not the worker's.
     */
    public static function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && $user['name'] !== '') {
                return $user['name'];
            }
        }

        return get_current_user();
    }

    public function askWorker(): void
    {
        Cache::put(self::ASKED, now()->getTimestamp(), now()->addDay());

        CheckWorker::dispatch()
            ->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    /**
     * Run by the worker: what it finds, kept for the page.
     */
    public function recordWorker(): void
    {
        Cache::put(self::WORKER, ['at' => now()->getTimestamp(), 'user' => self::processUser(), 'checks' => $this->onWorker()], now()->addWeek());
    }

    /**
     * Everything, in groups, each check with what to do when it is not right.
     *
     * @return array<int, array{title: string, checks: array<int, array{state: string, title: string, detail: string, fix: string|null}>}>
     */
    public function groups(): array
    {
        return array_values(array_filter([
            ['title' => 'This server', 'checks' => $this->application()],
            ['title' => 'The worker', 'checks' => $this->worker()],
            ['title' => 'Reviews', 'checks' => $this->reviews()],
            ['title' => 'Optional', 'checks' => $this->optional()],
        ], fn (array $group): bool => $group['checks'] !== []));
    }

    /**
     * How many checks are not right, for the badge in the menu.
     */
    public function wrong(): int
    {
        return collect($this->groups())->flatMap(fn (array $group): array => $group['checks'])->where('state', self::WRONG)->count();
    }

    /**
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function application(): array
    {
        $url = (string) config('app.url');
        $sample = CapacitySample::query()->latest('sampled_at')->first();
        $age = $sample === null ? null : (int) $sample->sampled_at->diffInSeconds(now());

        return [
            $this->check(
                ! config('app.debug') || ! app()->isProduction(),
                'Debug mode is off',
                match (true) {
                    ! config('app.debug') => 'APP_DEBUG is off.',
                    app()->isProduction() => 'APP_DEBUG is on in production: an error page shows settings and code to whoever causes one.',
                    default => 'APP_DEBUG is on, which is fine outside production.',
                },
                'Set APP_DEBUG=false in .env, then `php artisan config:cache`.',
            ),
            $this->check(
                str_starts_with($url, 'https://'),
                'The address is https',
                "APP_URL is {$url}. Webhooks, sign-in and the links in a review are built from it.",
                'Set APP_URL to the https address people open, then `php artisan config:cache`.',
            ),
            $this->check(
                config('queue.default') !== 'sync',
                'Reviews are queued',
                'The queue connection is '.config('queue.default').(config('review.container.connection') ? ', and reviews go on '.config('review.container.connection') : '').'.',
                'A review takes minutes; with QUEUE_CONNECTION=sync it would run inside the request that starts it. Use redis (with Horizon) or database.',
            ),
            $this->check(
                $age !== null && $age < 180,
                'The scheduler runs',
                $age === null ? 'It has never run: there is no measurement of the server.' : 'Its last measurement of the server is '.CarbonImmutable::now()->subSeconds($age)->diffForHumans().'.',
                'Add `* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1` to the crontab of the user the application runs as.',
            ),
        ];
    }

    /**
     * What the worker said when it was last asked, or that it did not answer.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function worker(): array
    {
        // Docker and Cloudflare sandboxes both run as jobs on this worker.
        if (! config('review.docker.enabled') && ! CloudflareSandbox::configured()) {
            return [[
                'state' => self::NOTE, 'title' => 'No runner reads the repository',
                'detail' => 'Neither Docker nor a Cloudflare sandbox is set up, so only a review from the diff alone can run.',
                'fix' => 'Once the worker has Docker, the runtime and the image, set REVIEW_DOCKER_ENABLED=true. For Cloudflare sandboxes, see Optional below.',
            ]];
        }

        $answer = Cache::get(self::WORKER);
        // A bare number comes back from Redis as a string.
        $asked = Cache::get(self::ASKED);
        $asked = is_numeric($asked) ? (int) $asked : null;
        $start = 'With Horizon: `php artisan horizon` under a process manager such as Supervisor, as the user that may use Docker. Without: `php artisan queue:work '
            .(config('review.container.connection') ?: config('queue.default')).' --queue='.config('review.container.queue').' --timeout=0`.';

        if (! is_array($answer)) {
            return [[
                'state' => is_int($asked) && now()->getTimestamp() - $asked > self::PATIENCE ? self::WRONG : self::NOTE,
                'title' => is_int($asked) ? 'No worker has answered' : 'The worker has not been asked yet',
                'detail' => is_int($asked)
                    ? 'A check was put on the queue "'.config('review.container.queue').'" '.CarbonImmutable::createFromTimestamp($asked)->diffForHumans().' and nothing picked it up.'
                    : 'Press "Ask the worker" to have it look at Docker, the image and its directories.',
                'fix' => is_int($asked) ? $start : null,
            ]];
        }

        $stale = is_int($asked) && $asked > $answer['at'] && now()->getTimestamp() - $asked > self::PATIENCE;

        return [
            [
                'state' => $stale ? self::WRONG : self::OK,
                'title' => $stale ? 'The worker stopped answering' : 'A worker listens to the reviews\' queue',
                'detail' => 'It last answered '.CarbonImmutable::createFromTimestamp((int) $answer['at'])->diffForHumans().', as the user '.$answer['user'].'.',
                'fix' => $stale ? $start : null,
            ],
            ...$answer['checks'],
        ];
    }

    /**
     * Looked at on the worker, as the worker: Docker, the runtime, the image and
     * the directories a review writes in.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    public function onWorker(): array
    {
        $docker = (string) config('review.container.docker');
        $runtime = (string) config('review.container.runtime');
        $image = (string) config('review.container.image');

        // On Cloudflare alone the worker needs no Docker: only its directories count.
        if (! config('review.docker.enabled')) {
            return $this->directories();
        }

        $version = rescue(fn () => Process::timeout(15)->run([$docker, 'version', '--format', '{{.Server.Version}}']), null, false);
        $hasDocker = $version !== null && $version->successful();
        $runtimes = $hasDocker ? (string) Process::timeout(15)->run([$docker, 'info', '--format', '{{json .Runtimes}}'])->output() : '';
        $hasImage = $hasDocker && Process::timeout(15)->run([$docker, 'image', 'inspect', '--format', '{{.Created}}', $image])->successful();

        $checks = [
            $this->check(
                $hasDocker,
                'Docker answers',
                $hasDocker ? 'Docker '.trim($version->output()).'.' : 'The worker cannot reach Docker: '.mb_substr(trim((string) $version?->errorOutput()) ?: 'the command is not there', 0, 200),
                'Install Docker on the worker and add its user to the docker group (`usermod -aG docker '.self::processUser().'`), then restart the worker.',
            ),
        ];

        if ($hasDocker && $runtime !== '') {
            $checks[] = $this->check(
                str_contains($runtimes, '"'.$runtime.'"'),
                "The runtime {$runtime} is installed",
                str_contains($runtimes, '"'.$runtime.'"') ? 'Reviewers run under it, apart from the worker\'s own kernel.' : "Docker does not know the runtime {$runtime}, so no reviewer can start.",
                $runtime === 'runsc'
                    ? 'Install gVisor (https://gvisor.dev/docs/user_guide/install/) and run `runsc install`, then `systemctl restart docker`.'
                    : 'Install the runtime, or set REVIEW_CONTAINER_RUNTIME to one Docker has.',
            );
        }

        if ($hasDocker && $runtime === '') {
            $checks[] = [
                'state' => self::NOTE, 'title' => 'Reviewers share the worker\'s kernel',
                'detail' => 'REVIEW_CONTAINER_RUNTIME is empty, so Docker\'s own runtime is used. That is no boundary to put other people\'s repositories behind.',
                'fix' => 'Install gVisor and set REVIEW_CONTAINER_RUNTIME=runsc.',
            ];
        }

        if ($hasDocker) {
            $checks[] = $this->check(
                $hasImage,
                'The reviewer\'s image is built',
                $hasImage ? $image.'.' : "There is no image {$image} on the worker.",
                'On the worker, in '.base_path().': `docker build --pull -t '.$image.' docker/reviewer`.',
            );
        }

        return [...$checks, ...$this->directories()];
    }

    /**
     * Whether the worker can write where a review writes.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function directories(): array
    {
        $checks = [];

        foreach (['work_directory' => 'the checkouts', 'cache_directory' => 'Composer\'s cache', 'sessions.directory' => 'the reviewers\' sessions', 'log.directory' => 'the logs'] as $key => $what) {
            $directory = (string) config("review.container.{$key}");

            if ($directory === '') {
                continue;
            }

            $writable = rescue(function () use ($directory): bool {
                File::ensureDirectoryExists($directory, 0700);

                return is_writable($directory);
            }, false, false);

            if (! $writable) {
                $checks[] = $this->check(false, "The worker can write {$what}", '', "Make {$directory} writable for the worker's user: `mkdir -p {$directory} && chown ".self::processUser()." {$directory}`.", "It cannot write in {$directory}.");
            }
        }

        if (collect($checks)->where('state', self::WRONG)->isEmpty()) {
            $checks[] = ['state' => self::OK, 'title' => 'The worker can write where a review writes', 'detail' => 'Checkouts, caches, sessions and logs.', 'fix' => null];
        }

        return $checks;
    }

    /**
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function reviews(): array
    {
        $guidance = ReviewInstruction::query()->whereNull('tenant_id')->where('key', ReviewInstructions::METHOD)->exists();
        $prices = ModelPrice::query()->count();

        $updates = $guidance ? ReviewInstructions::updates() : [];
        $edited = count(array_filter($updates, fn (array $update): bool => $update['state'] === 'edited'));

        return [
            match (true) {
                ! $guidance => ['state' => self::NOTE, 'title' => 'The review instructions are the ones that ship', 'detail' => 'None are kept here, so every review reads the copy that comes with it and nobody can change it.', 'fix' => 'Run `php artisan review:install`, or use "Update from this release" under Review instructions.'],
                $updates !== [] => ['state' => self::NOTE, 'title' => 'This release has newer review instructions', 'detail' => count($updates).' file(s) differ from what is kept here'.($edited > 0 ? ", {$edited} of them edited here" : '').'. Reviews read what is kept here until they are updated.', 'fix' => 'Review instructions → "Update from this release" shows what changes, file by file, and takes only what you pick.'],
                default => ['state' => self::OK, 'title' => 'The review instructions are in place', 'detail' => 'Every tenant reads the defaults kept here, and they are this release\'s.', 'fix' => null],
            },
            $this->check(
                $prices > 0,
                'Model prices are known',
                $prices > 0 ? "{$prices} model(s) have a price, so a review's tokens become a cost." : 'No model has a price, so every review costs "unknown".',
                'Run `php artisan review:sync-model-prices`; the scheduler does so every night.',
            ),
            $this->check(
                app(Runners::class)->options() !== [],
                'Something can review',
                app(Runners::class)->options() === [] ? 'No runner is set up.' : 'Set up: '.implode(', ', app(Runners::class)->options()).'. The platform\'s is '.Str::lower(rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->default())->label(), '?', false)).'; Admin → Settings changes it.',
                'Set REVIEW_DOCKER_ENABLED=true (with a worker that has Docker), set up Cloudflare sandboxes, or REVIEW_DIFF_ENABLED=true.',
            ),
        ];
    }

    /**
     * What an installation can do without, said as what it is: on, off, or half set.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function optional(): array
    {
        $checks = [];

        foreach ([
            'Signing in with Google' => ['services.google.client_id', 'services.google.client_secret', 'GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET, from an OAuth client with '.url('/sign-in/google/callback').' as redirect address'],
            'Connecting Asana by signing in' => ['services.asana.client_id', 'services.asana.client_secret', 'ASANA_CLIENT_ID and ASANA_CLIENT_SECRET, from an app with '.url('/connections/oauth/callback').' as redirect address'],
            'Connecting Jira by signing in' => ['services.atlassian.client_id', 'services.atlassian.client_secret', 'ATLASSIAN_CLIENT_ID and ATLASSIAN_CLIENT_SECRET, from an app with '.url('/connections/oauth/callback').' as redirect address'],
        ] as $title => [$id, $secret, $needs]) {
            $has = [filled(config($id)), filled(config($secret))];

            $checks[] = match (true) {
                $has === [true, true] => ['state' => self::OK, 'title' => $title, 'detail' => 'Set up.', 'fix' => null],
                $has === [false, false] => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Not set up; people use a password or a token instead.', 'fix' => "To turn it on, set {$needs}."],
                default => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Half set up: one of the two values is missing, so it is off.', 'fix' => "Set both: {$needs}."],
            };
        }

        array_push($checks, ...$this->cloudflare());

        return $checks;
    }

    /**
     * Cloudflare sandboxes: off, with every step to turn them on; or, once
     * anything of them is set, each part on its own, so what is missing shows.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function cloudflare(): array
    {
        $title = 'Cloudflare sandboxes';
        $url = config('review.cloudflare.url');
        $token = config('review.cloudflare.token');
        $diskName = (string) config('review.cloudflare.disk');
        $disk = (array) config("filesystems.disks.{$diskName}");
        $bucket = ['R2_ACCESS_KEY_ID' => $disk['key'] ?? null, 'R2_SECRET_ACCESS_KEY' => $disk['secret'] ?? null, 'R2_BUCKET' => $disk['bucket'] ?? null, 'R2_ENDPOINT' => $disk['endpoint'] ?? null];
        $missing = array_keys(array_filter($bucket, fn (mixed $value): bool => blank($value)));

        if (blank($url) && blank($token) && count($missing) === count($bucket)) {
            return [[
                'state' => self::NOTE, 'title' => $title,
                'detail' => 'Not set up; reviewers run in Docker on the worker. To run them in a Cloudflare sandbox instead:',
                'fix' => $this->cloudflareSteps(),
            ]];
        }

        $checks = [
            $this->check(
                filled($url) && filled($token),
                "{$title}: the Worker's address and token",
                "{$url}",
                'Set '.collect(['REVIEW_CLOUDFLARE_URL' => $url, 'REVIEW_CLOUDFLARE_TOKEN' => $token])->filter(fn (mixed $value): bool => blank($value))->keys()->implode(' and ')
                    .': the address `npx wrangler deploy` printed in cloudflare/worker, and the token you gave `npx wrangler secret put API_TOKEN`.',
                'Half set up, so sandboxes are off.',
            ),
            $this->check(
                $missing === [],
                "{$title}: the bucket's keys",
                "The disk {$diskName} on the bucket ".($bucket['R2_BUCKET'] ?? '').'.',
                'Set '.implode(', ', $missing).'. In the Cloudflare dashboard: R2 → Manage API tokens → Create API token, with Object Read & Write on the bucket only. It shows the key id, the secret and the S3 endpoint (`https://<account id>.r2.cloudflarestorage.com`).',
                'Missing: '.implode(', ', $missing).'.',
            ),
        ];

        if (filled($url) && filled($token)) {
            array_push($checks, ...$this->cloudflareWorker((string) $url, (string) $token));
        }

        if ($missing === []) {
            $checks[] = $this->cloudflareBucket($diskName, (string) $bucket['R2_BUCKET']);
        }

        return $checks;
    }

    /**
     * Every step from nothing, in this installation's own words.
     */
    protected function cloudflareSteps(): string
    {
        return implode("\n", [
            '1. **A Cloudflare account on the Workers Paid plan** ($5 a month): sandboxes run on Containers, which the free plan does not have.',
            '2. **The bucket**, from any machine with Node 20 or later and a checkout of review-central, your own laptop as well as this server: the Worker is deployed to Cloudflare, not installed here. `cd cloudflare/worker && npm install && npx wrangler login && npx wrangler r2 bucket create review-central`.',
            '3. **Its keys**: in the Cloudflare dashboard, R2 → Manage API tokens → Create API token, with Object Read & Write on that bucket only. Keep the access key id, the secret access key and the S3 endpoint it shows. Add lifecycle rules on the bucket that delete what is under `review/sandbox/` after a day, and what is under `review/cache/` (the vendor/ of each repository) after 14 days.',
            '4. **The Worker**: make a token with `openssl rand -hex 32`, give it to the Worker with `npx wrangler secret put API_TOKEN`, then `npx wrangler deploy`. It prints the Worker\'s address, such as `https://review-central-sandbox.<you>.workers.dev`.',
            '5. **This installation**, the only step on the server: in '.base_path('.env').' set `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET=review-central`, `R2_ENDPOINT`, `REVIEW_CLOUDFLARE_URL` (the address) and `REVIEW_CLOUDFLARE_TOKEN` (the token), then `php artisan config:cache` and `php artisan horizon:terminate`.',
            '6. **Back here**: this page then asks the Worker and the bucket. "A Cloudflare sandbox" can then be picked as the platform\'s runner under Settings, per repository under "Runs on", and per trial.',
            '',
            'More, such as the sandbox size and running the Worker on your own machine: cloudflare/README.md.',
        ]);
    }

    /**
     * Whether the Worker answers, with its bucket, and whether the tools of the
     * current setup are prepared.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function cloudflareWorker(string $url, string $token): array
    {
        $title = 'Cloudflare sandboxes: the Worker answers';
        $setup = CloudflareSandbox::setupScript();
        $version = $setup === null ? null : CloudflareSandbox::setupVersion($setup);
        $response = rescue(fn () => Http::withToken($token)->timeout(8)->connectTimeout(4)->get(rtrim($url, '/').'/health', array_filter(['setup' => $version])), null, false);
        $status = $response?->status() ?? 0;

        $worker = match (true) {
            $status === 200 && $response->json('bucket') === false => ['state' => self::WRONG, 'title' => $title, 'detail' => 'It answers, but has no bucket.', 'fix' => 'Check `r2_buckets` in cloudflare/worker/wrangler.jsonc names the bucket, then `npx wrangler deploy` again.'],
            $status === 200 => ['state' => self::OK, 'title' => $title, 'detail' => "The Worker at {$url} answers, with its bucket.", 'fix' => null],
            $status === 401 => ['state' => self::WRONG, 'title' => $title, 'detail' => 'The Worker refuses the token.', 'fix' => 'Make REVIEW_CLOUDFLARE_TOKEN the secret the Worker has: `npx wrangler secret put API_TOKEN` in cloudflare/worker.'],
            default => ['state' => self::WRONG, 'title' => $title, 'detail' => $status === 0 ? "The Worker at {$url} cannot be reached." : "The Worker answered {$status}.", 'fix' => 'Check REVIEW_CLOUDFLARE_URL against the address `npx wrangler deploy` printed, and its logs with `npx wrangler tail`.'],
        };

        if ($worker['state'] !== self::OK) {
            return [$worker];
        }

        // The code it runs against the code here: a change that was not deployed.
        $expected = CloudflareSandbox::workerVersion();
        $deployed = $response->json('version');
        $checks = [$worker];

        if ($expected !== null) {
            $checks[] = $this->check(
                $deployed === $expected,
                'Cloudflare sandboxes: the Worker is up to date',
                "Version {$expected}, as this installation has it.",
                'Deploy it again from a checkout of this version: `cd cloudflare/worker && npx wrangler deploy`.',
                is_string($deployed) ? "It runs version {$deployed}; this installation has {$expected}." : "It runs code from before it said its version; this installation has {$expected}.",
            );
        }

        // A Worker from before it could say leaves it out; then nothing is said.
        if ($version === null || ! is_bool($response->json('snapshot'))) {
            return $checks;
        }

        $title = 'Cloudflare sandboxes: the tools are prepared';
        $tried = Cache::get(PrepareCloudflareSandbox::RESULT);
        $failed = is_array($tried) && ($tried['version'] ?? null) === $version && ($tried['ok'] ?? true) === false;
        $going = Cache::get(PrepareCloudflareSandbox::PREPARING);
        $going = is_array($going) && ($going['version'] ?? null) === $version ? (int) ($going['at'] ?? 0) : null;

        return [...$checks, match (true) {
            $response->json('snapshot') === true => ['state' => self::OK, 'title' => $title, 'detail' => 'Reviews start from a snapshot with PHP, Composer and the reviewers in it.', 'fix' => null],
            $going !== null => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Being prepared, since '.CarbonImmutable::createFromTimestamp($going)->diffForHumans().'. The first time takes about four minutes; refresh this page to see it done.', 'fix' => 'To follow it: `npx wrangler tail` in cloudflare/worker.'],
            $failed => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Preparing them failed: '.mb_substr((string) ($tried['error'] ?? ''), 0, 400), 'fix' => 'Try "Prepare the sandbox" again; the Worker keeps the setup\'s output in the bucket under `review/snapshots/`, and `npx wrangler tail` shows its own.'],
            default => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Not yet, for this setup script. The first review prepares them, which takes a few minutes, and reviews that start meanwhile wait for it.', 'fix' => 'To have that done before anyone waits: "Prepare the sandbox", above.'],
        }];
    }

    /**
     * Whether this installation reaches the bucket with its keys: one look for a
     * file that is not there, which needs the same rights as the rest.
     *
     * @return array{state: string, title: string, detail: string, fix: string|null}
     */
    protected function cloudflareBucket(string $disk, string $bucket): array
    {
        $title = 'Cloudflare sandboxes: this installation reaches the bucket';

        try {
            Storage::disk($disk)->exists('review/setup-check');

            return ['state' => self::OK, 'title' => $title, 'detail' => "It can read {$bucket}.", 'fix' => null];
        } catch (Throwable $exception) {
            return [
                'state' => self::WRONG, 'title' => $title,
                // The storage wraps what the bucket said in "Unable to check existence".
                'detail' => 'It cannot: '.mb_substr(($exception->getPrevious() ?? $exception)->getMessage(), 0, 300),
                'fix' => 'Check R2_ENDPOINT (`https://<account id>.r2.cloudflarestorage.com`, without the bucket), R2_BUCKET, and that the API token has Object Read & Write on this bucket.',
            ];
        }
    }

    /**
     * @return array{state: string, title: string, detail: string, fix: string|null}
     */
    protected function check(bool $right, string $title, string $detail, string $fix, ?string $wrongDetail = null): array
    {
        return ['state' => $right ? self::OK : self::WRONG, 'title' => $title, 'detail' => $right ? $detail : ($wrongDetail ?? $detail), 'fix' => $right ? null : $fix];
    }
}
