<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\Integrations;
use App\Filament\LocalTime;
use App\Jobs\CheckWorker;
use App\Jobs\PrepareCloudflareSandbox;
use App\Models\CapacitySample;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewInstruction;
use App\Runners\CloudflareRunner;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\WorkerDeployment;
use App\Runners\ContainerRunner;
use App\Runners\Runners;
use App\Support\SetupGuides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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

    /** Seconds the Worker may take over the sandbox's tools before the page says where to look. */
    protected const PREPARE_SLOW = 600;

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
     * @return array<int, array{title: string, checks: array<int, array{state: string, title: string, detail: string, fix: string|null, guide?: string}>}>
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
     * How many checks are not right, for the badge in the menu: of the groups
     * given, or of running them now.
     *
     * @param  array<int, array{title: string, checks: array<int, array{state: string}>}>|null  $groups
     */
    public function wrong(?array $groups = null): int
    {
        return collect($groups ?? $this->groups())->flatMap(fn (array $group): array => $group['checks'])->where('state', self::WRONG)->count();
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
                $prices > 0 ? $prices.' '.Str::plural('model', $prices).' '.($prices === 1 ? 'has' : 'have')." a price, so a review's tokens become a cost." : 'No model has a price, so every review costs "unknown".',
                'Run `php artisan review:sync-model-prices`; the scheduler does so every night.',
            ),
            $this->check(
                app(Runners::class)->options() !== [],
                'Something can review',
                app(Runners::class)->options() === [] ? 'No runner is set up.' : 'Set up: '.implode(', ', array_map($this->inSentence(...), app(Runners::class)->options())).'. The platform\'s is '.$this->inSentence(rescue(fn (): string => app(Runners::class)->get(app(Runners::class)->default())->label(), '?', false)).'; Admin → Settings changes it.',
                'Set REVIEW_DOCKER_ENABLED=true (with a worker that has Docker), set up Cloudflare sandboxes, or REVIEW_DIFF_ENABLED=true.',
            ),
            ...$this->dockerBesideCloudflare(),
        ];
    }

    /**
     * Repositories that still review on Docker while Cloudflare sandboxes are set
     * up: there the code runs next to this server with the AI key in reach, where
     * a sandbox would run it away from both.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null}>
     */
    protected function dockerBesideCloudflare(): array
    {
        $runners = app(Runners::class);

        if (! array_key_exists(CloudflareRunner::NAME, $runners->options())) {
            return [];
        }

        $onDocker = Repository::query()->where('reviews_enabled', true)->get(['id', 'review_runner'])
            ->filter(fn (Repository $repository): bool => $runners->nameFor($repository->review_runner) === ContainerRunner::NAME)
            ->count();

        if ($onDocker === 0) {
            return [['state' => self::OK, 'title' => 'Reviews that read the code run in a sandbox', 'detail' => 'No repository reviews on Docker while Cloudflare sandboxes are set up.', 'fix' => null]];
        }

        $platform = $runners->default() === ContainerRunner::NAME;

        return [[
            'state' => self::NOTE,
            'title' => $onDocker === 1 ? 'A repository reviews on Docker' : "{$onDocker} repositories review on Docker",
            'detail' => 'Cloudflare sandboxes are set up, and they keep the pull request\'s code away from this server and the AI key out of its reach. On Docker the reviewer holds the key, next to this server.',
            'fix' => $platform
                ? 'Pick "A Cloudflare sandbox" under Settings → Reviews run on; a repository that picked Docker under Runs on keeps it.'
                : 'Change Runs on of those repositories to the platform\'s runner or to a Cloudflare sandbox.',
        ]];
    }

    /**
     * What an installation can do without, said as what it is: on, off, or half set.
     * Each names the guide that says where its keys are made, which the page
     * offers as "How to get this".
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null, guide?: string}>
     */
    protected function optional(): array
    {
        $checks = [];

        foreach ([
            'Signing in with Google' => ['services.google.client_id', 'services.google.client_secret', 'the client ID and secret under Apps (or GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET), from an OAuth client with '.url('/sign-in/google/callback').' as redirect address', SetupGuides::GOOGLE],
            'Replying and signing in with Bitbucket' => ['services.bitbucket.client_id', 'services.bitbucket.client_secret', 'the key and secret under Apps (or BITBUCKET_CLIENT_ID and BITBUCKET_CLIENT_SECRET), from an OAuth client with '.url('/connections/bitbucket/callback').' as callback URL', SetupGuides::BITBUCKET],
            'Connecting Asana by signing in' => ['services.asana.client_id', 'services.asana.client_secret', 'the client ID and secret under Apps (or ASANA_CLIENT_ID and ASANA_CLIENT_SECRET), from an app with '.url('/connections/oauth/callback').' as redirect address', SetupGuides::ASANA],
            'Connecting Jira by signing in' => ['services.atlassian.client_id', 'services.atlassian.client_secret', 'the client ID and secret under Apps (or ATLASSIAN_CLIENT_ID and ATLASSIAN_CLIENT_SECRET), from an app with '.url('/connections/oauth/callback').' as redirect address', SetupGuides::ATLASSIAN],
        ] as $title => [$id, $secret, $needs, $guide]) {
            $has = [filled(config($id)), filled(config($secret))];

            // Of Asana and Atlassian there can be apps in the list as well, each for some tenants.
            $listed = match ($guide) {
                SetupGuides::ASANA => Integrations::configured(Integrations::ASANA),
                SetupGuides::ATLASSIAN => Integrations::configured(Integrations::JIRA),
                default => false,
            };

            if ($listed && $has !== [true, true]) {
                $checks[] = ['state' => self::OK, 'title' => $title, 'detail' => 'Set up under Apps, for the tenants each app is for.', 'fix' => null];

                continue;
            }

            $checks[] = match (true) {
                $has === [true, true] => ['state' => self::OK, 'title' => $title, 'detail' => 'Set up.', 'fix' => null],
                $has === [false, false] => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Not set up; people use a password or a token instead.', 'fix' => "To turn it on, set {$needs}.", 'guide' => $guide],
                default => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Half set up: one of the two values is missing, so it is off.', 'fix' => "Set both: {$needs}.", 'guide' => $guide],
            };
        }

        $checks[] = $this->githubApp();

        array_push($checks, ...$this->cloudflare());

        return $checks;
    }

    /**
     * Reviewing as a GitHub App: on once every value it needs is set, which is
     * when GitHub App is offered as a connection.
     *
     * @return array{state: string, title: string, detail: string, fix: string|null, guide?: string}
     */
    protected function githubApp(): array
    {
        $title = 'Reviewing as a GitHub App';
        $values = ['GITHUB_APP_ID' => 'id', 'GITHUB_APP_SLUG' => 'slug', 'GITHUB_APP_CLIENT_ID' => 'client_id', 'GITHUB_APP_CLIENT_SECRET' => 'client_secret', 'GITHUB_APP_PRIVATE_KEY' => 'private_key'];
        $missing = array_keys(array_filter($values, fn (string $key): bool => blank(config("services.github_app.{$key}"))));

        // Not needed to offer the app, but without it every delivery from GitHub
        // is refused: tenants connect it, and no pull request is ever reviewed.
        $unsigned = blank(config('services.github_app.webhook_secret'));

        return match (true) {
            $missing === [] && $unsigned => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Tenants can connect the app, but GITHUB_APP_WEBHOOK_SECRET is not set, so every webhook delivery from GitHub is refused and no pull request is reviewed.', 'fix' => 'Fill in the webhook secret under Apps (or GITHUB_APP_WEBHOOK_SECRET), as in the app\'s settings on GitHub.', 'guide' => SetupGuides::GITHUB_APP],
            $missing === [] => ['state' => self::OK, 'title' => $title, 'detail' => 'Set up: tenants can connect GitHub as the app.', 'fix' => null],
            count($missing) === count($values) => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Not set up; GitHub connections use a token instead.', 'fix' => 'To turn it on, register a GitHub App and fill it in under Apps (or GITHUB_APP_ID, GITHUB_APP_SLUG, GITHUB_APP_CLIENT_ID, GITHUB_APP_CLIENT_SECRET, GITHUB_APP_PRIVATE_KEY and GITHUB_APP_WEBHOOK_SECRET in .env).', 'guide' => SetupGuides::GITHUB_APP],
            default => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Half set up, so it is off. Missing: '.implode(', ', $missing).'.', 'fix' => 'Fill in what is missing under Apps (or '.implode(', ', $missing).'), from the app\'s settings on GitHub.', 'guide' => SetupGuides::GITHUB_APP],
        };
    }

    /**
     * Cloudflare sandboxes: off, with every step to turn them on; or, once
     * anything of them is set, each part on its own, so what is missing shows.
     *
     * @return array<int, array{state: string, title: string, detail: string, fix: string|null, guide?: string}>
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

        $deploy = $this->cloudflareDeploy();

        if (blank($url) && blank($token) && count($missing) === count($bucket)) {
            return [[
                'state' => self::NOTE, 'title' => $title,
                'detail' => WorkerDeployment::ready()
                    ? 'Not deployed yet; reviewers run in Docker on the worker. The account and API token are set: Deploy the Worker, above, deploys it.'
                    : 'Not set up; reviewers run in Docker on the worker. To run them in a Cloudflare sandbox instead:',
                'fix' => $this->cloudflareSteps(),
                'guide' => SetupGuides::CLOUDFLARE_TOKEN,
            ], ...($deploy === null ? [] : [$deploy])];
        }

        $checks = [
            ...($deploy === null ? [] : [$deploy]),
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
                'The deploy makes the bucket, not its keys: those are a step of their own. In the Cloudflare dashboard: R2 → Manage API Tokens → Create Account API token, with Object Read & Write on the bucket only. Paste the Access Key ID and the Secret Access Key it shows under Settings → Cloudflare sandboxes (or '.implode(', ', $missing).' in .env).',
                'Missing: '.implode(', ', $missing).'.',
            ) + ($missing === [] ? [] : ['guide' => SetupGuides::R2_KEYS]),
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
     * How the last deploy from here went, while that is worth saying.
     *
     * @return array{state: string, title: string, detail: string, fix: string|null}|null
     */
    protected function cloudflareDeploy(): ?array
    {
        $title = 'Cloudflare sandboxes: the deploy from here';

        if (Cache::has(WorkerDeployment::DEPLOYING)) {
            return ['state' => self::NOTE, 'title' => $title, 'detail' => 'Being deployed, on the reviews\' queue: refresh in a moment.', 'fix' => null];
        }

        $last = Cache::get(WorkerDeployment::RESULT);

        if (! is_array($last)) {
            return null;
        }

        $when = CarbonImmutable::createFromTimestamp((int) ($last['at'] ?? 0))->diffForHumans();

        return ($last['ok'] ?? false)
            ? ['state' => self::OK, 'title' => $title, 'detail' => "Version {$last['version']} deployed {$when}, at {$last['url']}.", 'fix' => null]
            : ['state' => self::WRONG, 'title' => $title, 'detail' => "It failed {$when}: {$last['error']}", 'fix' => "What it printed last:\n\n```\n".implode("\n", array_slice(explode("\n", (string) ($last['log'] ?? '')), -30))."\n```"];
    }

    /**
     * Every step from nothing, in this installation's own words.
     */
    protected function cloudflareSteps(): string
    {
        return implode("\n", [
            '**From here**: on a machine whose reviews\' queue has Node 20 or later, set the Cloudflare account, an API token and the bucket\'s keys under Settings → Cloudflare sandboxes, then press Deploy the Worker above. It makes the bucket, deploys the Worker and keeps its address and token. Or by hand:',
            '',
            '1. **A Cloudflare account on the Workers Paid plan** ($5 a month): sandboxes run on Containers, which the free plan does not have.',
            '2. **The bucket**, from any machine with Node 20 or later and a checkout of review-central, your own laptop as well as this server: the Worker is deployed to Cloudflare, not installed here. `cd cloudflare/worker && npm install && npx wrangler login && npx wrangler r2 bucket create review-central`.',
            '3. **Its keys**: in the Cloudflare dashboard, R2 → Manage API Tokens → Create Account API token, with Object Read & Write on that bucket only. Keep the access key id, the secret access key and the S3 endpoint it shows. Add lifecycle rules on the bucket that delete what is under `review/sandbox/` after a day, and what is under `review/cache/` (the vendor/ of each repository) after 14 days.',
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
                WorkerDeployment::ready()
                    ? 'Deploy the Worker, above, deploys this version.'
                    : 'Deploy it again from a checkout of this version: `cd cloudflare/worker && npx wrangler deploy`, or set the account and API token under Settings and deploy it from here.',
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
        $going = is_array($going) && ($going['version'] ?? null) === $version ? $going : null;

        return [...$checks, match (true) {
            $response->json('snapshot') === true => ['state' => self::OK, 'title' => $title, 'detail' => 'Reviews start from a snapshot with PHP, Composer and the reviewers in it.', 'fix' => null],
            $going !== null => $this->preparing($title, $going),
            $failed => ['state' => self::WRONG, 'title' => $title, 'detail' => 'Preparing them failed: '.mb_substr((string) ($tried['error'] ?? ''), 0, 400), 'fix' => 'Try "Prepare the sandbox" again; the Worker keeps the setup\'s output in the bucket under `review/snapshots/`, and `npx wrangler tail` shows its own.'],
            default => ['state' => self::NOTE, 'title' => $title, 'detail' => 'Not yet, for this setup script. The first review prepares them, which takes a few minutes, and reviews that start meanwhile wait for it.', 'fix' => 'To have that done before anyone waits: "Prepare the sandbox", above.'],
        }];
    }

    /**
     * The tools while they are being prepared: waiting on the queue, or with the
     * Worker, and what to look at once that takes longer than it should.
     *
     * @param  array{at?: int, started?: int}  $going
     * @return array{state: string, title: string, detail: string, fix: string|null}
     */
    private function preparing(string $title, array $going): array
    {
        $clock = fn (int $timestamp): string => LocalTime::of(CarbonImmutable::createFromTimestamp($timestamp))->format('H:i');

        if (! isset($going['started'])) {
            $asked = (int) ($going['at'] ?? 0);
            $stuck = now()->getTimestamp() - $asked > self::PATIENCE;

            return [
                'state' => $stuck ? self::WRONG : self::NOTE,
                'title' => $title,
                'detail' => 'Asked for at '.$clock($asked).', on the queue "'.config('review.container.queue').'"'.($stuck ? '; nothing has picked it up.' : '. This page shows when it is done.'),
                'fix' => $stuck ? 'A worker for that queue picks it up: `php artisan horizon`, or `php artisan queue:work '.(config('review.container.connection') ?: config('queue.default')).' --queue='.config('review.container.queue').'`.' : null,
            ];
        }

        $started = (int) $going['started'];
        $slow = now()->getTimestamp() - $started > self::PREPARE_SLOW;

        return [
            'state' => self::NOTE,
            'title' => $title,
            'detail' => 'Being prepared since '.$clock($started).'. A first time takes about four minutes'.($slow ? ', so this is taking longer.' : '; this page shows when it is done.'),
            'fix' => $slow ? '`npx wrangler tail` in cloudflare/worker shows what the Worker is doing.' : null,
        ];
    }

    /**
     * Whether this installation reaches the bucket with its keys: one look for a
     * file that is not there, which needs the same rights as the rest.
     *
     * @return array{state: string, title: string, detail: string, fix: string|null}
     */
    public function cloudflareBucket(string $disk, string $bucket): array
    {
        $title = 'Cloudflare sandboxes: this installation reaches the bucket';
        // One object per check: the badge and the Setup page can check at the same
        // time, and one must not remove what the other is about to read back.
        $key = 'review/setup-check/'.Str::uuid();
        $what = 'write to';
        // Where each part comes from: the settings, or the environment where it sets one.
        $from = function (string $setting, string $variable): string {
            return app(PlatformSettings::class)->setByEnvironment($setting) === null ? 'under Settings' : "in {$variable} in .env";
        };
        $fix = 'Check the bucket\'s name '.$from(PlatformSettings::CLOUDFLARE_BUCKET, 'R2_BUCKET')
            .', the account it is in (the address is `https://<account id>.r2.cloudflarestorage.com`, from the account ID under Settings, or R2_ENDPOINT in .env, without the bucket)'
            .', and that the bucket\'s keys '.$from(PlatformSettings::R2_ACCESS_KEY_ID, 'R2_ACCESS_KEY_ID').' are of a token with Object Read & Write on this bucket.';

        // What a review does with it: an object written, read back and removed.
        // Not whether one is there: without the right to list the bucket, R2
        // answers that with 403 for an object that is not, which reviews never ask.
        try {
            $storage = Storage::disk($disk);
            $written = 'setup check '.now()->toIso8601String();
            $storage->put($key, $written);
            $what = 'read from';

            if ($storage->get($key) !== $written) {
                throw new RuntimeException('What it read back is not what it wrote.');
            }

            $what = 'remove from';
            $storage->delete($key);

            return ['state' => self::OK, 'title' => $title, 'detail' => "It can write to {$bucket}, read from it and remove from it.", 'fix' => null];
        } catch (Throwable $exception) {
            return [
                'state' => self::WRONG, 'title' => $title,
                // The storage wraps what the bucket said in "Unable to write file".
                'detail' => "It cannot {$what} {$bucket}: ".mb_substr(($exception->getPrevious() ?? $exception)->getMessage(), 0, 300),
                'fix' => match (true) {
                    $what === 'read from' => 'The keys may write but not read: give their token Read as well as Edit on this bucket (Object Read & Write), or make one that has both.',
                    str_contains($exception->getMessage().($exception->getPrevious()?->getMessage() ?? ''), 'SignatureDoesNotMatch') => 'The secret is not the one of this key ID: take the Secret Access Key (64 characters) that Cloudflare showed with this Access Key ID, not the token value, and both from the same token.',
                    default => $fix,
                },
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

    /** A runner's label inside a sentence: "The diff alone…" becomes "the diff alone…", "Docker…" stays. */
    protected function inSentence(string $label): string
    {
        return (string) preg_replace('/^The /', 'the ', $label);
    }
}
