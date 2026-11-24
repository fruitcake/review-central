# Configuration

Everything is set in `.env`, and read from `config/review.php`, `config/services.php` and Laravel's own config files. Run `php artisan config:clear` (or `config:cache` in production) after a change. `.env.example` has the settings a feature needs to be turned on; this page has all of them, with their defaults.

The usual Laravel settings apply as well: `APP_URL`, `APP_KEY`, the database (`DB_*`), the queue (`QUEUE_CONNECTION`), the cache (`CACHE_STORE`), sessions and mail (`MAIL_*`, needed for invitations). See [Installation](./installation).

## Runners

| Setting | Default | What it does |
|---|---|---|
| `REVIEW_DEFAULT_RUNNER` | — | The platform's runner until an administrator picks one under **Admin → Settings**: `diff`, `docker` or `cloudflare`. Unset, or not set up: a Cloudflare sandbox once it is set up, else the diff. Docker runs only when it is picked. |
| `REVIEW_DIFF_ENABLED` | `true` | Whether the review from the diff can be picked. |
| `REVIEW_DIFF_TIMEOUT` | `900` | Seconds the AI provider may take to answer a review from the diff. |
| `REVIEW_DIFF_MAX_BYTES` | `600000` | The most diff one request carries; files past it are left out, and the review says which. |
| `REVIEW_DIFF_OPENAI_MODEL` | — | The OpenAI model for the review from the diff when a repository names none; unset, the Laravel AI SDK's default for OpenAI. Anthropic's is the SDK's smartest. |
| `REVIEW_TURN_NOTES` | `true` | Off turns the notes on whose turn off for the whole installation. A team turns them off, or picks their model, under Review defaults. |
| `REVIEW_TURN_OPENAI_MODEL` | — | The OpenAI model that writes those notes; unset, the Laravel AI SDK's cheapest for OpenAI. Anthropic's is the SDK's cheapest; another provider's is the model its connection names. |
| `REVIEW_DOCKER_ENABLED` | `false` | Whether Docker on the worker can be picked. Turn it on once the worker has Docker, gVisor and the reviewer's image. |

## Docker

| Setting | Default | What it does |
|---|---|---|
| `REVIEW_CONTAINER_DOCKER` | `docker` | The Docker command, for a worker where it is not on the `PATH`. |
| `REVIEW_CONTAINER_RUNTIME` | `runsc` | The OCI runtime the reviewer runs under. `runsc` is gVisor; Docker's own `runc` shares the host's kernel with the code under review. |
| `REVIEW_CONTAINER_IMAGE` | `review-central/reviewer:latest` | The reviewer's image, built from `docker/reviewer`. |
| `REVIEW_CONTAINER_NETWORK` | `bridge` | The Docker network of the reviewer. It needs to reach the AI provider and nothing else. |
| `REVIEW_CONTAINER_SERVER_NETWORK` | `bridge` | The network of the steps that hold credentials for this server or the repository's host. |
| `REVIEW_CONTAINER_SERVER_URL` | `APP_URL` | The address of this server the reviewer is told, when it differs from `APP_URL`. |
| `REVIEW_CONTAINER_MEMORY` | `4g` | The reviewer's memory limit. A review's page shows the most it used. |
| `REVIEW_CONTAINER_CPUS` | `2` | The reviewer's processor limit. |
| `REVIEW_CONTAINER_PIDS` | `1024` | The most processes the reviewer may start. |
| `REVIEW_CONTAINER_SAMPLE_SECONDS` | `10` | Seconds between two looks at the memory a running container uses. |
| `REVIEW_CONTAINER_TIMEOUT` | `1800` | Seconds the reviewer may take. The job gets this plus 600 for each review it does, up to three in a row. |
| `REVIEW_CONTAINER_QUESTION_TIMEOUT` | `600` | Seconds an answer to a question on a pull request may take. |
| `REVIEW_CONTAINER_WORK_DIRECTORY` | `storage/app/review/work` | Where a review's checkout lives while it runs. |
| `REVIEW_CONTAINER_CACHE_DIRECTORY` | `storage/app/review/cache` | What Composer downloaded, per repository. Empty keeps nothing. |
| `REVIEW_CONTAINER_CACHE_DAYS` | `14` | Days without a review before a repository's cache is removed. |
| `REVIEW_CONTAINER_SESSION_DIRECTORY` | `storage/app/review/sessions` | The reviewer's home per repository and branch, which the next review resumes. Empty keeps none. |
| `REVIEW_CONTAINER_SESSION_DISK` | — | A filesystem disk, such as `r2`, a session is also kept on, so another worker can pick it up. |
| `REVIEW_CONTAINER_SESSION_DAYS` | `14` | Days without a review before a branch's session is removed. |
| `REVIEW_CONTAINER_SESSION_RESUMES` | `3` | Reviews in a row that resume a session before one starts fresh. |
| `REVIEW_CONTAINER_PROCESSES` | `2` | Reviews that run side by side; Horizon's reviews supervisor uses the same number. |
| `REVIEW_CONTAINER_CONNECTION` | — | The queue connection reviews go on: `reviews` with Redis and Horizon, empty for the default connection. |
| `REVIEW_CONTAINER_QUEUE` | `reviews` | The queue reviews go on. |
| `REVIEW_CONTAINER_LOG` | `true` | Whether what a review prints is kept, for its page to show. It may quote the repository's code. |
| `REVIEW_CONTAINER_LOG_DIRECTORY` | `storage/app/review/logs` | Where the logs are kept. |
| `REVIEW_CONTAINER_LOG_DISK` | — | A filesystem disk, such as `r2`, the log is also copied to, so a server other than the worker can show it. |
| `REVIEW_CONTAINER_LOG_DAYS` | `14` | Days a log is kept. |

## Cloudflare sandboxes

See [`cloudflare/README.md`](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md) for deploying the Worker.

| Setting | Default | What it does |
|---|---|---|
| `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN` | — | What deploys the Worker from here (`php artisan review:deploy-worker`, or Deploy the Worker on the setup page). Also under Settings. |
| `REVIEW_CLOUDFLARE_WORKER` | — | The Worker's name, for that deploy. Also under Settings; `review-central-sandbox` when neither names one. |
| `REVIEW_CLOUDFLARE_URL` | — | The deployed Worker's address. A deploy from here keeps it under Settings instead. |
| `REVIEW_CLOUDFLARE_TOKEN` | — | The token the Worker was deployed with. |
| `REVIEW_CLOUDFLARE_LOCAL` | `false` | Use the Worker on this machine (`npx wrangler dev`) while no deployed one is named. |
| `REVIEW_CLOUDFLARE_LOCAL_URL` | `http://127.0.0.1:8787` | Where that local Worker answers. |
| `REVIEW_CLOUDFLARE_DISK` | `r2` | The filesystem disk on the bucket the Worker is bound to. |
| `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` | — | That bucket's credentials, for the `r2` disk. Also under Settings. |
| `REVIEW_CLOUDFLARE_PROXY_KEYS` | `true` | Keep the AI providers' keys in the Worker, and give the sandbox a placeholder the Worker swaps on the way to the provider. Off only for a reviewer that does not trust the Worker's certificate. |
| `REVIEW_CLOUDFLARE_INSTANCE` | `standard-1` | The sandbox's size: `lite`, `basic`, `standard-1` to `standard-4`. Also under Settings → Cloudflare sandboxes. The capacity page advises one from what the reviews measured: their memory at most, and how often the steps around the model were short of processor on each size, with a button to switch. |
| `REVIEW_CLOUDFLARE_SETUP` | `true` | Install PHP, Composer and the reviewers' tools in a fresh sandbox, and snapshot that. Off when the Worker's image has them. |
| `REVIEW_CLOUDFLARE_RATE_VCPU`, `REVIEW_CLOUDFLARE_RATE_MEMORY`, `REVIEW_CLOUDFLARE_RATE_DISK` | Cloudflare's list prices | Dollars per vCPU-second, GiB-second of memory and GB-second of disk, to estimate what a sandbox cost. |

## Reviewers

| Setting | Default | What it does |
|---|---|---|
| `CLAUDE_REVIEW_EFFORT_DEFAULT` | `high` | Claude Code's effort where neither the repository nor the tenant sets one. |
| `CODEX_REVIEW_EFFORT_DEFAULT` | `medium` | The same for Codex. |
| `OCR_REVIEW_EFFORT_DEFAULT` | `medium` | The same for OpenCodeReview. |
| `PI_REVIEW_EFFORT_DEFAULT` | `medium` | The same for Pi. |
| `REVIEW_SECURITY_TIMEOUT` | `3600` | Seconds a security review of a whole branch may take. |
| `SECURITY_REVIEW_MAX_BUDGET` | `20` | The most a security review may cost, in dollars. |
| `SECURITY_REVIEW_MODEL`, `SECURITY_REVIEW_EFFORT` | the reviewer's own | The model and effort of a security review. |

## Signing in and connecting

| Setting | What it does |
|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Sign in with Google. Also under Apps in the admin panel. See [Setting up a tenant](./tenants#signing-in-with-google). |
| `BITBUCKET_CLIENT_ID`, `BITBUCKET_CLIENT_SECRET` | The client ID and secret of a Bitbucket OAuth client, for people to reply on Bitbucket as themselves. Also under Apps in the admin panel. See [Setting up a tenant](./tenants#replying-on-bitbucket-as-yourself). |
| `ASANA_CLIENT_ID`, `ASANA_CLIENT_SECRET`, `ASANA_OAUTH_SCOPE` | Connect Asana by signing in there; the ID and secret also under Apps in the admin panel. See [Setting up a tenant](./tenants#signing-in-to-asana-and-jira). |
| `ATLASSIAN_CLIENT_ID`, `ATLASSIAN_CLIENT_SECRET` | The same for Jira. Also under Apps in the admin panel. |
| `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_WEBHOOK_SECRET` | Review on GitHub as a GitHub App, and sign in and reply on GitHub as yourself through it. Also under Apps in the admin panel. See [Setting up a tenant](./tenants#github-as-an-app). |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | The key pair the [MCP servers](./reviews#in-claude-and-other-ai-assistants) sign their sign-in tokens with. Not needed: without them a pair is made the first time and kept, encrypted, with the installation's settings. |

## Queue

| Setting | Default | What it does |
|---|---|---|
| `DB_QUEUE_RETRY_AFTER` | `90` | With the database queue: seconds before a job that has not finished is handed out again. Set it to `7500` for reviews. With Redis and Horizon, the `reviews` connection is sized already. |

## Live updates

`BROADCAST_CONNECTION` with the `PUSHER_*` or `MERCURE_*` settings: see [Live updates](./live-updates).
