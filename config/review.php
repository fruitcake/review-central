<?php

use App\Enums\ConnectionKind;
use App\Llm\AnthropicDriver;
use App\Llm\DeepSeekDriver;
use App\Llm\GeminiDriver;
use App\Llm\GroqDriver;
use App\Llm\MistralDriver;
use App\Llm\OpenAiDriver;
use App\Llm\OpenRouterDriver;
use App\Llm\XaiDriver;
use App\Runners\CloudflareRunner;
use App\Runners\ContainerRunner;
use App\Runners\DiffRunner;
use App\SourceControl\BitbucketDriver;
use App\SourceControl\GitDriver;
use App\SourceControl\GitHubAppDriver;
use App\SourceControl\GitHubDriver;
use App\Tickets\AsanaDriver;
use App\Tickets\GitHubIssuesDriver;
use App\Tickets\JiraDriver;

return [

    /*
    | Every service a tenant can connect, by kind and by the name stored on the
    | connection. Adding a provider is a driver class and a line here.
    */
    'drivers' => [
        ConnectionKind::SourceControl->value => [
            'bitbucket' => BitbucketDriver::class,
            // Read and cloned from, never posted to: a review of theirs is kept here.
            'github' => GitHubDriver::class,
            // As this installation's GitHub App, where one is set up (services.github_app).
            'github-app' => GitHubAppDriver::class,
            'git' => GitDriver::class,
        ],
        ConnectionKind::Tickets->value => [
            'asana' => AsanaDriver::class,
            'jira' => JiraDriver::class,
            'github' => GitHubIssuesDriver::class,
        ],
        ConnectionKind::Llm->value => [
            'anthropic' => AnthropicDriver::class,
            'openai' => OpenAiDriver::class,
            // A key and a model each: Pi reviews with them, and the review from the diff.
            'gemini' => GeminiDriver::class,
            'mistral' => MistralDriver::class,
            'groq' => GroqDriver::class,
            'deepseek' => DeepSeekDriver::class,
            'xai' => XaiDriver::class,
            'openrouter' => OpenRouterDriver::class,
        ],
    ],

    /*
    | How a review runs, chosen per repository, or the platform's default under
    | Admin → Settings: Docker on this installation's worker, a Cloudflare sandbox,
    | or the diff alone through the AI provider's API. The first two run the
    | reviewer a repository's settings pick; the diff has no reviewer to choose.
    | A runner is there to choose once it is set up. Adding one is a class and a
    | line here.
    */
    'runners' => [
        'docker' => ContainerRunner::class,
        'cloudflare' => CloudflareRunner::class,
        'diff' => DiffRunner::class,
    ],

    /*
    | The platform's runner until an administrator picks one under Settings: the
    | diff, which needs nothing but an AI provider's key. When it is not set up,
    | the first runner that is.
    */
    'default_runner' => env('REVIEW_DEFAULT_RUNNER', 'diff'),

    /*
    | Docker on this installation's worker, with gVisor as its runtime: on once
    | the worker has Docker, the runtime and the reviewer's image.
    */
    'docker' => [
        'enabled' => (bool) env('REVIEW_DOCKER_ENABLED', false),
    ],

    /*
    | The diff runner: one request to the tenant's AI provider with the pull
    | request's diff, from a queued job on the same queue as the container reviews.
    | A repository chooses it under "Runs on".
    */
    'diff' => [
        'enabled' => (bool) env('REVIEW_DIFF_ENABLED', true),
        // Seconds the provider may take to answer.
        'timeout' => (int) env('REVIEW_DIFF_TIMEOUT', 900),
        // The most bytes of diff one request carries; files past it are left out
        // and the review says which.
        'max_bytes' => (int) env('REVIEW_DIFF_MAX_BYTES', 600_000),
        // The OpenAI model when a repository names none. Anthropic's is in its driver.
        'openai_model' => env('REVIEW_DIFF_OPENAI_MODEL', 'gpt-6.1-sol'),
    ],

    // The effort a reviewer runs at where neither the repository nor the tenant
    // sets one. Given to the reviewer rather than left to its own default, so a
    // review records what it ran at, also after a reviewer's default changes.
    'default_effort' => [
        'claude-code' => env('CLAUDE_REVIEW_EFFORT_DEFAULT', 'high'),
        'codex-cli' => env('CODEX_REVIEW_EFFORT_DEFAULT', 'medium'),
        'ocr' => env('OCR_REVIEW_EFFORT_DEFAULT', 'medium'),
        'pi' => env('PI_REVIEW_EFFORT_DEFAULT', 'medium'),
    ],

    /*
    | A security review of a whole branch, which reads more than a pull request's
    | review does and so gets more time: the seconds its reviewer may take.
    */
    'security' => [
        'timeout' => (int) env('REVIEW_SECURITY_TIMEOUT', 3600),
        // The most one may cost in dollars, where it stops; and the model and
        // effort, where they are not the reviewer's own.
        'max_budget' => env('SECURITY_REVIEW_MAX_BUDGET', 20),
        'model' => env('SECURITY_REVIEW_MODEL'),
        'effort' => env('SECURITY_REVIEW_EFFORT'),
    ],

    /*
    | The container runner: a queued job on this installation's own worker, which
    | clones the repository and runs the reviewer in a sandboxed container.
    */
    'container' => [
        // The Docker command, for a worker where it is not on the PATH.
        'docker' => env('REVIEW_CONTAINER_DOCKER', 'docker'),
        // The OCI runtime the reviewer runs under: runsc is gVisor. The default
        // runtime of Docker (runc) shares the host's kernel with the pull request's
        // code, which is no boundary to host other people's repositories behind.
        'runtime' => env('REVIEW_CONTAINER_RUNTIME', 'runsc'),
        'image' => env('REVIEW_CONTAINER_IMAGE', 'review-central/reviewer:latest'),
        // The Docker network of the reviewer. It needs the AI provider and nothing else.
        'network' => env('REVIEW_CONTAINER_NETWORK', 'bridge'),
        // The network of the steps that talk to this server, and the address they
        // reach it at when that differs from APP_URL.
        'server_network' => env('REVIEW_CONTAINER_SERVER_NETWORK', 'bridge'),
        'server_url' => env('REVIEW_CONTAINER_SERVER_URL'),
        'memory' => env('REVIEW_CONTAINER_MEMORY', '4g'),
        'cpus' => env('REVIEW_CONTAINER_CPUS', '2'),
        'pids' => (int) env('REVIEW_CONTAINER_PIDS', 1024),
        // Seconds between two looks at how much memory a running container uses;
        // the most that was seen is kept on the review.
        'sample_seconds' => (int) env('REVIEW_CONTAINER_SAMPLE_SECONDS', 10),
        // Seconds an answer to a question on a pull request may take.
        'question_timeout' => (int) env('REVIEW_CONTAINER_QUESTION_TIMEOUT', 600),
        // Seconds the reviewer may take.
        'timeout' => (int) env('REVIEW_CONTAINER_TIMEOUT', 1800),
        'work_directory' => env('REVIEW_CONTAINER_WORK_DIRECTORY', storage_path('app/review/work')),
        // What Composer downloaded, kept per repository so the next review of it
        // installs from disk, and removed when the repository had no review for
        // this many days. An empty directory keeps nothing.
        'cache_directory' => env('REVIEW_CONTAINER_CACHE_DIRECTORY', storage_path('app/review/cache')),
        'cache_days' => (int) env('REVIEW_CONTAINER_CACHE_DAYS', 14),
        // The reviewer's home, kept per repository and branch so the next review of
        // a pull request resumes the session of the last, and a question asked on
        // the pull request is answered from it. An empty directory keeps none.
        'sessions' => [
            'directory' => env('REVIEW_CONTAINER_SESSION_DIRECTORY', storage_path('app/review/sessions')),
            // A filesystem disk, such as `r2`, a session is also kept on after each
            // review, so another worker can pick it up. Empty keeps it on this one.
            'disk' => env('REVIEW_CONTAINER_SESSION_DISK'),
            // Removed when the branch had no review for this many days, and at once
            // when its pull request is merged or declined.
            'days' => (int) env('REVIEW_CONTAINER_SESSION_DAYS', 14),
            // Reviews in a row that resume a session before one starts fresh.
            'resumes' => (int) env('REVIEW_CONTAINER_SESSION_RESUMES', 3),
        ],
        // Reviews that run side by side; Horizon's reviews supervisor uses the same number.
        'processes' => (int) env('REVIEW_CONTAINER_PROCESSES', 2),
        // The queue connection the reviews go on: `reviews` with Redis and Horizon,
        // empty for the default connection.
        'connection' => env('REVIEW_CONTAINER_CONNECTION'),
        'queue' => env('REVIEW_CONTAINER_QUEUE', 'reviews'),
        // What a review prints, kept so its page can show it while it runs. It may
        // quote the repository's code; turn it off to keep none of that on disk.
        'log' => [
            'enabled' => (bool) env('REVIEW_CONTAINER_LOG', true),
            'directory' => env('REVIEW_CONTAINER_LOG_DIRECTORY', storage_path('app/review/logs')),
            // A filesystem disk, such as `r2`, the log is also copied to while the
            // review runs and when it ends, so its page can show it from a server
            // that is not the worker. Empty keeps it on the worker alone.
            'disk' => env('REVIEW_CONTAINER_LOG_DISK'),
            'days' => (int) env('REVIEW_CONTAINER_LOG_DAYS', 14),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare sandboxes
    |--------------------------------------------------------------------------
    |
    | The other place a container review can run its reviewer: a Cloudflare
    | sandbox, a microVM of its own that is gone when the review ends, started by
    | the Worker in cloudflare/worker. The worker here still clones and files the
    | report; only the reviewer's step moves. See cloudflare/README.md.
    */
    'cloudflare' => [
        // The Worker on this machine, started with `npx wrangler dev` in
        // cloudflare/worker, instead of one that is deployed: its sandboxes run
        // in the local Docker, and files travel through the Worker itself, since
        // its bucket is a local stand-in no disk here can reach. Off unless it
        // is set, and only used when no deployed Worker is named below.
        'local' => (bool) env('REVIEW_CLOUDFLARE_LOCAL', false),
        'local_url' => env('REVIEW_CLOUDFLARE_LOCAL_URL', 'http://127.0.0.1:8787'),
        // The Worker's address and the token it was deployed with. Without both,
        // and without the local one, there is no such sandbox to choose.
        'url' => env('REVIEW_CLOUDFLARE_URL'),
        'token' => env('REVIEW_CLOUDFLARE_TOKEN'),
        // The disk on the bucket the Worker is bound to: the checkout goes up
        // through it and the reviewer's files come back through it.
        'disk' => env('REVIEW_CLOUDFLARE_DISK', 'r2'),
        // lite, basic, standard-1 to standard-4, as Cloudflare names them.
        // Empty here, so the size under Settings can fill it; standard-1 when
        // neither picks one.
        'instance' => env('REVIEW_CLOUDFLARE_INSTANCE'),
        // Installs PHP, Composer and the reviewers' CLIs in a fresh sandbox on
        // Cloudflare's own image; the Worker keeps a snapshot of that, which later
        // reviews start from. Off when the Worker was deployed with an image
        // that has them.
        'setup' => (bool) env('REVIEW_CLOUDFLARE_SETUP', true),
        // The AI providers' keys stay in the Worker: the sandbox gets a placeholder,
        // which the Worker swaps for the key in a request to the provider's host.
        // Off only for a reviewer that will not trust the Worker's certificate.
        'proxy_keys' => (bool) env('REVIEW_CLOUDFLARE_PROXY_KEYS', true),
        // What deploys the Worker from here (`php artisan review:deploy-worker`, or
        // Deploy on the setup page): the account, an API token that may edit its
        // Workers, containers and R2, and the Worker's name. The same can be set
        // under Settings, which fills what is empty here, as for the address,
        // the token and the bucket's keys.
        'account' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        // Empty here, so the name under Settings can fill it; the deploy falls
        // back to review-central-sandbox.
        'worker' => env('REVIEW_CLOUDFLARE_WORKER'),
        // What an instance has, and what Cloudflare charges for it in dollars, to
        // estimate what a review's sandbox cost: memory and disk for the time it
        // ran, and a processor for all of that time, which is the most it can be.
        'instances' => [
            'lite' => ['vcpu' => 1 / 16, 'memory_gib' => 0.25, 'disk_gb' => 2],
            'basic' => ['vcpu' => 1 / 4, 'memory_gib' => 1, 'disk_gb' => 4],
            'standard-1' => ['vcpu' => 1 / 2, 'memory_gib' => 4, 'disk_gb' => 8],
            'standard-2' => ['vcpu' => 1, 'memory_gib' => 6, 'disk_gb' => 12],
            'standard-3' => ['vcpu' => 2, 'memory_gib' => 8, 'disk_gb' => 16],
            'standard-4' => ['vcpu' => 4, 'memory_gib' => 12, 'disk_gb' => 20],
        ],
        'rates' => [
            'vcpu_second' => (float) env('REVIEW_CLOUDFLARE_RATE_VCPU', 0.000020),
            'memory_gib_second' => (float) env('REVIEW_CLOUDFLARE_RATE_MEMORY', 0.0000025),
            'disk_gb_second' => (float) env('REVIEW_CLOUDFLARE_RATE_DISK', 0.00000007),
        ],
    ],

];
