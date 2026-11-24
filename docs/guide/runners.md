# Where a review runs

How a review runs is its **runner**. There are three, from the lightest to the strongest:

| Runner | What the reviewer sees | What it needs |
|---|---|---|
| `diff` | The pull request's diff, with its ticket, comments and earlier findings. Nothing is cloned and nothing runs. | Only an AI provider key. The default. |
| `docker` | The whole checkout, in a throwaway container under gVisor on your own server. | Docker, gVisor and a worker of its own. |
| `cloudflare` | The whole checkout, in a Cloudflare sandbox. The safest that reads the code. | A Cloudflare account on the Workers Paid plan, and the Worker deployed: from **Admin → Setup**, or by hand. |

For a review that reads the code, the Cloudflare sandbox is the one to use: the code runs in a microVM of its own away from your server, and the AI provider's key never enters it. Docker is for your own repositories on a machine of its own. [Security](./security#which-runner) compares the three.

The platform's runner is picked under **Admin → Settings**, from the ones that are set up, and a repository can pick its own under **Runs on**. **Admin → Setup** says which runners are set up and what the others still need.

![The setup page](/screenshots/setup.png)

## A container on this server

The review runs as a queued job on this installation's own worker. What it prints is kept in a file while it runs, and the review's page in the tenant panel shows it as it goes, asking again every few seconds. The log may quote the repository's code; it is removed after `REVIEW_CONTAINER_LOG_DAYS` (14), and `REVIEW_CONTAINER_LOG=false` keeps none. The connection's webhook starts a review for every new commit on a pull request that is not a draft, and the pull request shows it among its checks as "AI Review": a build status on Bitbucket, a commit status on GitHub.

The job clones the repository on the worker, and then runs the review's scripts in containers that are thrown away:

- the reviewer is the only step that sees the pull request's code. It gets the key of the AI provider that runs it and nothing else: no other provider's key, no credential for this server or for the repository's host. It cannot change the scripts the other steps run, and it runs with the review's own settings for its CLI, not the pull request's: see [Security](./security#what-it-does).
- the worker itself decides whether to review and writes what the reviewer reads: the pull request, its comments, the ticket and the instructions. That reads no code, so it needs no container.
- the worker reads the report the reviewer wrote, as data, and files it: the review on the pull request and, on Bitbucket, the Code Insights report on the commit. Each review uses one container; a review that is turned away uses none.

To turn it on, on a machine that runs `php artisan queue:work --queue=reviews`:

1. Install Docker and a sandboxing runtime. The default is gVisor (`runsc`); set `REVIEW_CONTAINER_RUNTIME` for another, such as Kata. Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
2. Build the image: `docker build -t review-central/reviewer:latest docker/reviewer`.
3. Set `REVIEW_DOCKER_ENABLED=true`. Until then a repository on Docker says in its readiness that the runner is not set up, and no review starts.
4. Set the queue connection's `retry_after` above the job's timeout, or a long review is started a second time. The job may take the reviewer's limit plus ten minutes (`REVIEW_CONTAINER_TIMEOUT` + 600 seconds) for each review it does, and up to three when it [carries on with a new commit](#carrying-on-with-a-new-commit). With Redis the `reviews` connection is sized for that already (7,500 seconds); on the database queue set `DB_QUEUE_RETRY_AFTER=7500`.

A review's page counts its duration up while it runs, and shows the most memory its container was seen to use, sampled every `REVIEW_CONTAINER_SAMPLE_SECONDS` (10), next to the limit `REVIEW_CONTAINER_MEMORY` gives it. The limit is a ceiling, not a reservation; where reviews stay well under it, lowering it lets more of them run side by side.

The worker can start containers, which is as good as root on that machine: give it a machine of its own that reaches only the database, the cache and Docker. `config/review.php` has the limits, the networks of the steps and the address the containers reach this server at.

The reviewer step installs `vendor/` as production has it (`composer install --no-dev`), so the reviewer can read the framework's and the packages' own code. Composer runs no scripts and no plugins there, and no server is started from the checkout: the pull request names both in its own files, and the step holds the AI provider's key. The installed `vendor/` is kept in the repository's cache directory under the hash of `composer.json` and `composer.lock`, and the next review with the same lock unpacks it instead of installing; the three newest are kept.

The clone has the full history without the file contents (`--filter=blob:none`); the worker fetches the contents of what the review compares before the reviewer starts. Nothing of a repository is kept after its review, except what Composer downloaded for it: that stays in `REVIEW_CONTAINER_CACHE_DIRECTORY`, one directory per repository, so the next review of it installs from disk. It is removed when the repository had no review for `REVIEW_CONTAINER_CACHE_DAYS` (14). Set the directory empty to keep nothing.

## The diff alone

The lightest review, and the default until another runner is set up and picked. The job reads the pull request's diff from the repository's host and sends it to the tenant's AI provider in one request, with the pull request, its ticket, its comments and the findings of earlier reviews. What comes back is posted as any review is: the summary, a comment per finding, tasks, and an approval or a request for changes.

Nothing is cloned and no container starts, so it needs no Docker and finishes in a minute or two. The reviewer sees only what the diff shows: it cannot read the code around a change, a caller or a definition, so it finds less and is told to report only what the diff itself establishes. Lock files, built files and binary files are left out, and so are whole files once the diff passes `REVIEW_DIFF_MAX_BYTES` (600,000); the review's log names each.

Which provider is asked follows the repository's reviewer settings: Codex means OpenAI, anything else Anthropic, and a tenant without that provider is asked through the one it connected first. The model and effort are the ones set for that reviewer. A Claude model that is an alias only Claude Code knows, such as `opus`, and no model at all, give way to the Laravel AI SDK's smartest for Anthropic; without a Codex model, OpenAI is asked for `REVIEW_DIFF_OPENAI_MODEL`, or else the SDK's default for OpenAI. On Anthropic's own API a request a safety classifier declines is run again on the model Anthropic recommends for it (`fallbacks`), which a diff full of security code can need.

`REVIEW_DIFF_ENABLED=false` takes the choice away.

## A Cloudflare sandbox

::: tip
Cloudflare's Sandbox SDK and its snapshots are in beta at Cloudflare. Reports of how it went are welcome as [GitHub issues](https://github.com/fruitcake/review-central/issues).
:::

The reviewer can also run in a [Cloudflare sandbox](https://developers.cloudflare.com/sandbox/): the job stays on this server, and the pull request's code runs on Cloudflare's side, in a container of its own for every review. It needs no Docker on the server and scales with the number of reviews: a worker on this server only waits for its sandbox.

### Before you start

- A Cloudflare account on the **Workers Paid** plan. Sandboxes run as containers, and the free plan has none.
- Node 20 or later on the machine that runs the reviews' queue (`queue:work --queue=reviews`, or Horizon). The Worker is deployed from there with Wrangler.

### Setting it up

Everything is done from the admin panel. It takes about ten minutes, most of it waiting.

**1. Make an API token in Cloudflare.**
Go to [API tokens](https://dash.cloudflare.com/profile/api-tokens) → **Create Token** → **Create Custom Token**. Under **Account Resources** pick your account, and give it these permissions, all at account level:

| Permission | What it is for |
|---|---|
| Workers Scripts: Edit | deploys the Worker |
| Containers: Edit | deploys the sandbox's container with it |
| Workers R2 Storage: Edit | makes the bucket, and binds it to the Worker |

The token is only used to deploy. Reviews never see it.

**2. Fill in the account and the token.**
Under **Admin → Settings → Cloudflare sandboxes**, fill in the **account ID** (32 characters, on the account's overview in the dashboard, under **API**) and the **API token**. Leave the Worker's name (`review-central-sandbox`) and the bucket's (`review-central`) as they are unless you need other names. Save, and press **Test**: it says whether Cloudflare accepts the token.

**3. Deploy the Worker.**
On **Admin → Setup**, press **Deploy the Worker**. On the reviews' queue it installs Wrangler, makes the bucket and deploys the Worker with a token of its own. The first deploy takes a few minutes; refresh the page to see how it went. When it worked, the Worker's address and token are saved under Settings for you. If the token lacks a permission, the page says which.

From a shell, `php artisan review:deploy-worker` does the same.

You can also make the bucket yourself first, under **R2 Object Storage → Create bucket**, with the same name. The deploy then uses that bucket.

**4. Make keys for the bucket.**
The bucket exists now, and **R2 Object Storage** in the dashboard lists it. Open [R2 API tokens](https://dash.cloudflare.com/?to=/:account/r2/api-tokens) (in the dashboard: **Manage API Tokens** under **Account Details** on R2's overview) and choose **Create Account API token**. Give it **Object Read & Write**, and under **Specify bucket(s)** only `review-central` (or the name you picked).

An account token keeps working when people leave, but only a Super Administrator can make one. A **User API token** works as well, with the same permission, but stops working when its user leaves the Cloudflare account. A token with only **Admin Read only** cannot write to the bucket, so reviews fail with it. Cloudflare then shows an **Access Key ID** (32 characters) and a **Secret Access Key** (64 characters), once. Paste those two under **Admin → Settings → Cloudflare sandboxes**, not the token value shown above them. Save and press **Test** again: it should now report the token, the bucket and the Worker as working.

**5. Prepare the sandbox.**
On **Admin → Setup**, press **Prepare the sandbox**. The Worker installs PHP, Composer and the reviewers' tools once and keeps a snapshot of that, so the first review does not have to wait for it. This takes a few minutes.

**6. Use it.**
Once the Worker is set up, a Cloudflare sandbox is the platform's runner unless **Admin → Settings** picks another, or `REVIEW_DEFAULT_RUNNER` names one. A repository can pick its own under **Runs on**. **Admin → Setup** shows whether the Worker answers and is up to date.

::: tip
The **How to get this** link next to the token and the bucket's keys in Settings shows steps 1 and 4 inside the app. A value set in `.env` (`CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN`, `R2_*`, `REVIEW_CLOUDFLARE_*`) goes before what is saved in Settings, and the field says so.
:::

### Keeping the tools current

The snapshot belongs to one version of the setup script and of the reviewers' CLIs. Pressing **Prepare the sandbox** again does nothing until one of them changes. Every hour the scheduler asks npm for the newest Claude Code and Codex (`review:refresh-sandbox`). For a new release it has a new snapshot made, and reviews switch to that release once the snapshot is there, so no review waits for an install. Until then a review updates its CLI in a step of its own, which costs about half a minute and says so in its log.

After an update of Review Central that changes the Worker, **Admin → Setup** says the Worker is out of date: press **Deploy the Worker** again.

### By hand, or locally

Deploying without the admin panel, and running the Worker on your own machine with `npx wrangler dev` to try it, is described in [`cloudflare/README.md`](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md).

### What the sandbox holds

The checkout, the review's scripts and the reviewer's session go to the sandbox through the bucket, and the report and the session come back the same way. With **Clone in the Cloudflare sandbox** on, under the source control connection's settings, the sandbox clones the repository itself and the worker clones nothing; a public repository on GitHub or Bitbucket is always cloned there. The host's token then stays in the Worker, which adds it only to fetching that one repository, never to a push ([how](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md#cloning-in-the-sandbox)). The CLIs and caches in the reviewer's home stay behind: the snapshot has the tools. The AI provider's key never enters the sandbox: it holds a placeholder, and the Worker puts the key in only on a request over HTTPS to that provider's own host.

### Its size, and what it measured

Every review in a sandbox runs on the size picked under **Settings → Cloudflare sandboxes** (`standard-1` unless changed), from the next review on; a bigger one costs more per second. Each review measures, per step, how long it took, the most memory it used and how long it waited for a processor, and keeps that with its cost in the sandbox. Composer's step is measured as *composer (cached)* when it starts from a `vendor/` that was kept, and as *composer (fresh)* when it installs from nothing, which takes many times longer.

On **Platform**, the capacity section advises a size from those measurements: one whose memory has room for what nineteen in twenty reviews used, and one up from a size whose steps around the model were short of processor a quarter of the time or more. A size that was short of processor is not advised again, and just after a change the advice waits for five reviews on the new size. **Use** switches to the advised size. A table per size shows each step's average time, the time nine in ten stay within, its most memory and how long it waited for a processor, with what a review took and cost in the sandbox.

## The reviewer's session, and asking it a question

The reviewer's home is kept on the worker per repository and branch (`REVIEW_CONTAINER_SESSION_DIRECTORY`), with the session Claude Code ended its review in. The next review of that branch, from a fresh clone, resumes the session and starts from what the last one read and found. After `REVIEW_CONTAINER_SESSION_RESUMES` (3) reviews in a row a review starts fresh: a session that followed that many commits carries what was true of code that is gone. The home is removed when the pull request is merged or declined, and after `REVIEW_CONTAINER_SESSION_DAYS` (14) without a review. A trial keeps a session of its own, apart from the branch's, for questions on its page; the pull request's own reviews never resume it. The Codex and OpenCodeReview reviewers do not resume a session.

A comment on a pull request that starts with `@review` is a question for the reviewer:

> @review did you mean the export or the import in F2?

The reviewer answers in a reply under that comment. A job clones the branch, fetches the branch it merges into, and runs Claude Code once in the sandbox a review runs in, in a copy of the branch's session (a trial's own, for a question on a trial's page). It is also handed the review the question is about, its summary and findings, so it can answer about them also where no session was kept. The answer is no part of the review: it changes no finding, and the copy is thrown away. An answer is capped at 2 dollars, a pull request gets ten an hour, and the review's page lists the questions with what each cost. It needs the container runner and an Anthropic connection; without either the reply says so.

## Carrying on with a new commit

A push that arrives while a pull request is being reviewed normally waits, and then starts a review of its own from a fresh clone. With **New commit during a review** set to carry on, under the tenant's default review settings or a repository's own, the job that is reviewing carries on instead: its review is published first, then it fetches the branch into the checkout it already has and reviews again, once or up to twice in a row. Claude Code resumes the session of the review before, so it starts from what it already read and found; the other reviewers start fresh in the same checkout.

A review is listed from the moment it is queued, not only once it starts running. The review's list has it as **Queued**, and its commit on the pull request shows "AI Review" in progress, with "Waiting for a worker" or, for a commit the reviewing job will carry on with, "Waiting for the review of the commit before it". When the job gets to it, the same record becomes the running review. A queued review that is told there is nothing to review, or that a newer commit overtakes, is closed as skipped and its commit says so.

While this is on, the reviewer cannot write the clone's `.git` directory, because git runs on the worker again afterwards with the host's credentials, and it keeps a home directory between its reviews that no other step sees. A job gets the time of every review it may do, at most two after its own: up to two hours with the default limit. Horizon's timeout and the `reviews` connection's `retry_after` are sized for that; Horizon's stop timeout, set where it runs (Forge, systemd or Supervisor), has to be too: see [Installation](./installation).

## Horizon

With Redis as the queue, Horizon runs the workers, and `/horizon` shows them to an administrator. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `REVIEW_CONTAINER_CONNECTION=reviews`; that connection's `retry_after` already fits a review.

`php artisan horizon` runs everything on one machine: the application's own jobs, and one process per review at a time, `REVIEW_CONTAINER_PROCESSES` of them (2 by default). Forge keeps it running; elsewhere use systemd or Supervisor. Run `php artisan horizon:terminate` after a deploy so it restarts on the new code. A review that is running is finished first.

To move the reviews to a machine of their own later, start Horizon there with `--environment=worker` and on the application's server with `--environment=application`. Both then use the same Redis and the same cache: the review lock lives in the cache.

## Another system

A runner of your own is a class: see [Extending](./extending#adding-a-runner).
