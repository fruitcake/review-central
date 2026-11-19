# Review Central

An AI review for pull requests, for several tenants at once. Each tenant connects its own source control, ticket trackers and AI providers; the review runs in a sandboxed container on this installation's own worker, and this application decides when to review, hands the reviewer its context, posts the result on the pull request and keeps what every run found and cost.

## How a review runs

1. A push to a pull request reaches this server through the connection's webhook, which queues a review.
2. The job clones the repository and decides whether to review. A draft, a commit that was already reviewed and a pull request with a review still running are skipped, before any container starts.
3. The review step runs the reviewer (Claude Code, the Codex CLI or OpenCodeReview) on the checkout, with the tenant's AI provider key. It reads the pull request, its comments, the ticket and the review instructions from the context the server gave it.
4. The worker reads the report the reviewer wrote and files it, which posts it: a summary in the pull request's description, a comment per finding, replies in the threads people answered, tasks for what blocks the merge, and an approval or a request for changes. A review that finds nothing blocking but names something only a person can settle, such as whether production data a migration deletes may go, does neither: the description reads "Manual review required" with the points to check, and the pull request waits for a person's approval. A merge check that requires an approval is what then holds the merge. That the review cannot run the code is never such a point.
5. On the next push the reviewer reads its own earlier findings and the replies to them, marks what was fixed and resolves those threads.

A `/review` comment, or marking a draft ready, starts a review without a push.

## Try it in ten minutes

Review a public pull request on GitHub from its diff, on your own machine. Nothing is cloned and no code is run: the review from the diff sends the pull request's diff to your AI provider. It needs PHP 8.3+, Composer, Node and one AI provider's key (Anthropic, OpenAI, or another the review knows).

```sh
git clone https://github.com/fruitcake/review-central.git && cd review-central
composer setup               # .env, an app key, SQLite, the tables, the front end
php artisan review:install   # the review's instructions, model prices, your admin account and a first tenant
php artisan serve
```

And in a second terminal, the queue the reviews run on:

```sh
php artisan queue:work --queue=reviews,default   # or, with Redis: php artisan horizon
```

Then, at http://127.0.0.1:8000:

1. Sign in with the account `review:install` made; it opens the tenant it made.
2. **AI providers**: add your key.
3. **Reviews → Try without posting**: paste a GitHub pull request's address, such as `https://github.com/laravel/framework/pull/12345`, and keep "Runs on" at the diff alone. A public repository needs no connection of its own.

The result is on the review's page a minute later. Nothing is posted on GitHub.

From there:

- **A reviewer that reads the repository** (Claude Code, Codex, OpenCodeReview, Pi) needs a sandbox for the pull request's code: [Cloudflare sandboxes](cloudflare/README.md), which can also run on this machine with `npx wrangler dev` and your own Docker, or Docker on the worker (below).
- **Reviews posted on your pull requests** need your host: a source control connection on Bitbucket, and the webhook it shows.
- **Admin → Setup** says what is in place, and what to do about what is not.

The review from the diff is the default runner until another is set up and picked under Admin → Settings. It is the safe one: nothing of the pull request runs. It is also the weaker one, since it sees the diff and not the code around it.

## Installing

Requires PHP 8.3 or newer, a database, a cache that every process shares (the review lock lives in it) and a scheduler. For the container runner also Redis, Docker and gVisor.

### With Laravel Forge

One server that runs everything, provisioned and deployed by Forge. Forge installs nginx, PHP, the database and Redis; only Docker and gVisor are installed by hand. The site runs as the `forge` user, or as a user of its own when the site is isolated. The steps below say `forge`: with isolation, read the site's user there, and run the `sudo` commands as `forge`, since an isolated user has no sudo. The names of Forge's screens change now and then, so the steps name what to set, not where each button is.

**1. The server**

Create an app server on Ubuntu 24.04 with PHP 8.3 or newer, MySQL (or PostgreSQL) and Redis. Keep the sudo password Forge shows once: step 5 needs it.

The review containers can reach anything on the server that listens on every interface, so check that MySQL and Redis only answer on `127.0.0.1`, or are closed by the firewall for the Docker network:

```sh
sudo ss -ltnp | grep -E '3306|5432|6379'
```

**2. The site**

Add a site for the public domain, for example `review.example.com`, with web directory `/public`, and install the repository `fruitcake/review-central` on the branch to deploy. Create the database and its user in Forge, and request a Let's Encrypt certificate for the site: the host only delivers webhooks over HTTPS.

**3. The environment**

Forge creates `.env` with an `APP_KEY` and the database login. `APP_KEY` encrypts every stored token and API key. Back it up apart from the database: without it they cannot be read again.

Set these in the site's environment:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://review.example.com

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Only with Docker and gVisor from step 5.
REVIEW_DOCKER_ENABLED=true
REVIEW_CONTAINER_CONNECTION=reviews
REVIEW_CONTAINER_RUNTIME=runsc
```

`APP_URL` has to be the public address: it goes into every webhook, and the review's steps call it.

**4. The deploy script**

The script Forge proposes for a Laravel site is enough, as long as it ends up doing this. There are no front-end assets to build, so an `npm` step can go.

```sh
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize
# Horizon finishes the review it is running, then Forge starts it again on the new code.
$FORGE_PHP artisan horizon:terminate
```

Deploy once, then run this once on the server, over SSH in the site's directory, because it asks questions:

```sh
php artisan review:install
```

It imports the default review instructions, fetches the model prices and the exchange rates, and asks for the name, email address and password of the first administrator. It can be run again; each step is skipped once it is done.

**5. Docker and gVisor**

Over SSH as `forge`:

```sh
sudo apt update && sudo apt install -y docker.io
curl -fsSL https://gvisor.dev/archive.key | sudo gpg --dearmor -o /usr/share/keyrings/gvisor-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/gvisor-archive-keyring.gpg] https://storage.googleapis.com/gvisor/releases release main" \
    | sudo tee /etc/apt/sources.list.d/gvisor.list
sudo apt update && sudo apt install -y runsc
sudo runsc install
sudo systemctl restart docker
sudo usermod -aG docker forge
```

Membership of the `docker` group is as good as root on the server, whichever user gets it, so isolating the site does not shield other sites from this one. Give this application a server of its own.

`docker run --rm --runtime=runsc hello-world`, in a new SSH session as the site's user, shows whether the runtime works. Then build the reviewer's image in the site's directory, and again whenever `docker/reviewer` changes:

```sh
docker build -t review-central/reviewer:latest docker/reviewer
```

**6. Horizon and the scheduler**

Turn on Horizon for the site, or add a background process that runs `php artisan horizon` in the site's directory as `forge`. Set the time it may take to stop to 2700 seconds, so a review that is running is finished before Horizon stops. A process that was already running before step 5 has to be restarted once, to get the `docker` group.

Turn on the Laravel scheduler for the site, or add a scheduled job that runs `php artisan schedule:run` every minute as `forge`. The scheduler closes runs that never reported, refreshes the model prices and the exchange rates each night, keeps Horizon's metrics and samples the capacity each minute.

**7. Sign in**

`/admin` with the administrator from step 4. From there, "Setting up a tenant" below.

### Without Forge

The same on a plain Ubuntu server: nginx with PHP-FPM on `public/`, MySQL and Redis on `127.0.0.1`, step 5 with the web server's user in place of `forge`, `php artisan horizon` under systemd or Supervisor with a stop timeout of 2700 seconds, and `php artisan schedule:run` every minute from cron.

### The keys, and where each one goes

| Key | Made where | Kept where |
|---|---|---|
| `APP_KEY` | Forge, when it creates the site (`php artisan key:generate` elsewhere) | `.env` on the server. Encrypts everything below. |
| Source control token | Bitbucket: workspace settings, access tokens. It has to write pull requests, read the repository, set a build status on a commit, and manage webhooks when the webhook is added for you. | A tenant's source control connection. |
| AI provider key | The Anthropic or OpenAI console. | A tenant's AI provider connection. |
| Ticket tracker token | Jira, Asana or GitHub. | A tenant's ticket connection. |
| Webhook secret | Made with the source control connection. | Shown under "Webhook" on that connection. "Add it for me" stores it with the host; by hand it is pasted into each repository's webhook. |

A connection's secrets are stored encrypted and never shown again; an edit that leaves one empty keeps it.

### Without Redis

For a try-out, the defaults of `.env.example` work: SQLite, and the database for the queue and the cache. Run `php artisan queue:work --queue=reviews,default --timeout=2400` in place of Horizon, and set `DB_QUEUE_RETRY_AFTER=2700`.

## Updating

After pulling a new release, on the server (and on every worker):

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate     # or restart your queue workers
```

Then look at **Admin → Setup**, which says what the release still needs:

- **This release has newer review instructions.** Under Review instructions, **Update from this release** shows per file what changes, as a diff. A file someone here edited is not picked, since taking the new text would undo that edit; read its diff and carry over what you want by hand. A tenant's own copy of a file is never touched.
- **The Worker is not up to date**, when Cloudflare sandboxes are set up: deploy it again from this release, `cd cloudflare/worker && npx wrangler deploy`. Then **Prepare the sandbox**, if the setup script changed.
- **The reviewer's image**, on Docker: rebuild it when `docker/reviewer` changed, `docker build --pull -t review-central/reviewer:latest docker/reviewer`.

## Setting up a tenant

In the admin panel, at `/admin`:

1. Add the tenant, and the users who may open it. A new user is mailed an invitation to choose a password, so the installation needs working mail (`MAIL_*` in `.env`) and a running queue. From the list of users the invitation can be sent again, and a password reset mailed.

In the tenant's panel, at `/app/<tenant>`:

2. **Source control**: add the host (Bitbucket Cloud, with a workspace access token that may write pull requests). **AI providers**: add a key (Anthropic, OpenAI, or another provider), with the model and effort Claude Code or Codex reviews at on it. "Test" checks the credentials.
3. **Tickets**, for ticket context: the tracker (Jira, Asana or GitHub issues) with its credentials, and which references in a pull request title name a ticket there: `PREFIX-123` with a list of prefixes, or `#1234`.
4. **Repositories**: choose the connection and search for the repository by name. The workspace is the connection's, and the stack is read from the repository's `composer.json` when it is added; editing the repository changes the stack, its name here and the instructions it reads.
5. On the source control connection, **Webhook** shows the address and secret. The webhook is what starts a review: for a push to a pull request, a `/review` comment and a draft marked ready. **Add it for me** adds it for every repository of the workspace, which needs the webhook scope on the token: Bitbucket has no screen for a workspace's webhooks, only an API. Without that scope, add it to each repository under Repository settings, Webhooks.

Added by hand, the webhook takes the pull request events Created, Updated, Merged, Declined and Comment created. Nothing is needed in the repository itself.

## Where a review runs

What a repository's reviews run on is a runner, which it chooses under **Runs on**: a container on this server, which is the default, or the diff alone.

### A container on this server

The review runs as a queued job on this installation's own worker. What it prints is kept in a file while it runs, and the review's page in the tenant panel shows it as it goes, asking again every few seconds. The log may quote the repository's code; it is removed after `REVIEW_CONTAINER_LOG_DAYS` (14), and `REVIEW_CONTAINER_LOG=false` keeps none. The connection's webhook starts a review for every new commit on a pull request that is not a draft, and the pull request shows it among its builds as "AI Review".

The job clones the repository on the worker, and then runs the review's scripts in containers that are thrown away:

- the reviewer is the only step that sees the pull request's code. It gets the AI provider's key and nothing else: no credential for this server or for the repository's host. It cannot change the scripts the other steps run.
- the worker itself decides whether to review and writes what the reviewer reads: the pull request, its comments, the ticket and the instructions. That reads no code, so it needs no container.
- the worker reads the report the reviewer wrote, as data, and files it: the review on the pull request and the Code Insights report on the commit. A review is one container, and one that is turned away is none.

To turn it on, on a machine that runs `php artisan queue:work --queue=reviews`:

1. Install Docker and a sandboxing runtime. The default is gVisor (`runsc`); set `REVIEW_CONTAINER_RUNTIME` for another, such as Kata. Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
2. Build the image: `docker build -t review-central/reviewer:latest docker/reviewer`.
3. Set `REVIEW_DOCKER_ENABLED=true`. Until then a repository on Docker says in its readiness that the runner is not set up, and no review starts.
4. Set the queue connection's `retry_after` above the job's timeout (the reviewer's limit of 30 minutes plus 10), or a long review is started a second time.

A review's page shows the most memory its container was seen to use, sampled every `REVIEW_CONTAINER_SAMPLE_SECONDS` (10), next to the limit `REVIEW_CONTAINER_MEMORY` gives it. The limit is a ceiling, not a reservation; where reviews stay well under it, lowering it lets more of them run side by side.

The worker can start containers, which is as good as root on that machine: give it a machine of its own that reaches only the database, the cache and Docker. `config/review.php` has the limits, the networks of the steps and the address the containers reach this server at.

The reviewer step installs `vendor/` as production has it (`composer install --no-dev`), so the reviewer can read the framework's and the packages' own code. Composer runs no scripts and no plugins there, and no server is started from the checkout: the pull request names both in its own files, and the step holds the AI provider's key. The installed `vendor/` is kept in the repository's cache directory under the hash of `composer.json` and `composer.lock`, and the next review with the same lock unpacks it instead of installing; the three newest are kept.

The clone has the full history without the file contents (`--filter=blob:none`); the worker fetches the contents of what the review compares before the reviewer starts. Nothing of a repository is kept after its review, except what Composer downloaded for it: that stays in `REVIEW_CONTAINER_CACHE_DIRECTORY`, one directory per repository, so the next review of it installs from disk. It is removed when the repository had no review for `REVIEW_CONTAINER_CACHE_DAYS` (14). Set the directory empty to keep nothing.

### The diff alone, through the provider's API

A lighter review, which a repository chooses under **Runs on**. It is nobody's default. The job reads the pull request's diff from the repository's host and sends it to the tenant's AI provider in one request, with the pull request, its ticket, its comments and the findings of earlier reviews. What comes back is posted as any review is: the summary, a comment per finding, tasks, and an approval or a request for changes.

Nothing is cloned and no container starts, so it needs no Docker and finishes in a minute or two. The reviewer sees only what the diff shows: it cannot read the code around a change, a caller or a definition, so it finds less and is told to report only what the diff itself establishes. Lock files, built files and binary files are left out, and so are whole files once the diff passes `REVIEW_DIFF_MAX_BYTES` (600,000); the review's log names each.

Which provider is asked follows the repository's reviewer settings: Codex means OpenAI, anything else Anthropic, and a tenant with one provider is asked through that one. The model and effort are the ones set for that reviewer. A Claude model that is an alias only Claude Code knows, such as `opus`, gives way to `claude-opus-5-5`; without a Codex model, OpenAI is asked for `REVIEW_DIFF_OPENAI_MODEL`. On Anthropic's own API a request a safety classifier declines is run again on the model Anthropic recommends for it (`fallbacks`), which a diff full of security code can need.

`REVIEW_DIFF_ENABLED=false` takes the choice away.

### The reviewer's session, and asking it a question

The reviewer's home is kept on the worker per repository and branch (`REVIEW_CONTAINER_SESSION_DIRECTORY`), with the session Claude Code ended its review in. The next review of that branch, from a fresh clone, resumes the session and starts from what the last one read and found. After `REVIEW_CONTAINER_SESSION_RESUMES` (3) reviews in a row a review starts fresh: a session that followed that many commits carries what was true of code that is gone. The home is removed when the pull request is merged or declined, and after `REVIEW_CONTAINER_SESSION_DAYS` (14) without a review. A trial keeps none. The Codex and OpenCodeReview reviewers do not resume a session.

A comment on a pull request that starts with `@review` is a question for the reviewer:

> @review did you mean the export or the import in F2?

The reviewer answers in a reply under that comment. A job clones the branch and runs Claude Code once in the sandbox a review runs in, in a copy of the branch's session, so it answers from its last review where there is one and from the code otherwise. The answer is no part of the review: it changes no finding, and the copy is thrown away. An answer is capped at 2 dollars, a pull request gets ten an hour, and the review's page lists the questions with what each cost. It needs the container runner and an Anthropic connection; without either the reply says so.

### Carrying on with a new commit

A push that arrives while a pull request is being reviewed normally waits, and then starts a review of its own from a fresh clone. With **New commit during a review** set to carry on, under the tenant's default review settings or a repository's own, the job that is reviewing carries on instead: its review is published first, then it fetches the branch into the checkout it already has and reviews again, once or up to twice in a row. Claude Code resumes the session of the review before, so it starts from what it already read and found; the other reviewers start fresh in the same checkout.

A review is shown from the moment it is started, not from the moment it begins. The review's list has it as **Queued**, and its commit on the pull request shows "AI Review" in progress, with "Waiting for a worker" or, for a commit the reviewing job will carry on with, "Waiting for the review of the commit before it". When the job gets to it, the same record becomes the running review. A queued review that is told there is nothing to review, or that a newer commit overtakes, is closed as skipped and its commit says so.

While this is on, the reviewer cannot write the clone's `.git` directory, because git runs on the worker again afterwards with the host's credentials, and it keeps a home directory between its reviews that no other step sees. A job gets the time of every review it may do, at most two after its own; Horizon's timeout and the queue's `retry_after` are sized for that.

### Horizon

With Redis as the queue, Horizon runs the workers, and `/horizon` shows them to an administrator. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `REVIEW_CONTAINER_CONNECTION=reviews`; that connection's `retry_after` already fits a review.

`php artisan horizon` runs everything on one machine: the application's own jobs, and one process per review at a time, `REVIEW_CONTAINER_PROCESSES` of them (2 by default). Forge keeps it running; elsewhere use systemd or Supervisor. Run `php artisan horizon:terminate` after a deploy so it restarts on the new code. A review that is running is finished first.

To move the reviews to a machine of their own later, start Horizon there with `--environment=worker` and on the application's server with `--environment=application`. Both then use the same Redis and the same cache: the review lock lives in the cache.

### Runners

How a review runs is its runner:

- `docker`: the reviewer in a container on this installation's worker (above).
- `cloudflare`: the reviewer in a Cloudflare sandbox, the job still on this worker (`cloudflare/README.md`).
- `diff`: only the pull request's diff, sent to the tenant's AI provider. No clone and no reviewer to choose.

The platform's runner is picked under Admin → Settings, from the ones that are set up. Until someone picks one it is `REVIEW_DEFAULT_RUNNER` (`diff`), or, when that is not set up, the first runner that is. A repository follows it unless it picks its own under "Runs on". Which reviewer runs on Docker or Cloudflare (Claude Code, Codex, OpenCodeReview, Pi) is the review defaults' choice, per tenant and per repository.

### Another system

A runner is a class that implements `App\Runners\ReviewRunner`, registered under `runners` in `config/review.php`: how a review is started, and whether a run is still alive.

## Live updates

Without anything set, an open page asks again by itself: the review's page every three seconds while the review runs. With a broadcaster the pages are told instead: the overview and the list of reviews when a review starts or ends, and a review's page when its log grows. Set `BROADCAST_CONNECTION` in `.env`:

- `pusher`: Pusher, or a server of your own that speaks its protocol (Reverb, Soketi). Set `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET` and `PUSHER_APP_CLUSTER`, and `PUSHER_HOST`, `PUSHER_PORT` and `PUSHER_SCHEME` for a server of your own.
- `mercure`: a Mercure hub. Set `MERCURE_URL` (where this server publishes), `MERCURE_PUBLIC_URL` (where browsers subscribe) and `MERCURE_JWT_SECRET` (the hub's key for publishers and subscribers, at least 32 bytes). The hub tells who may listen from a cookie this site sets, so it has to be on this site's domain or a subdomain of it.
- `null`, the default: nothing is broadcast.

Run `php artisan config:clear` (or `config:cache`) after changing it. Nothing has to be built: the browser's side is in `public/js/laravel-echo.js`, and is made with what `.env` says.

A broadcast only says that something changed, never what. The page then reads it from this server, so no code and no review text reaches the broadcaster, and a page that missed one is only late: it still asks every ten seconds while a review runs.

Broadcasts are sent as they happen, not from the queue: the worker that would send them may be the one running the review. A broadcaster that does not answer within a few seconds is skipped.

## A tenant's colours

Every panel has the same look: the type, the square corners and the layout are the same for everyone, in a default deep teal. A tenant's panel can wear the client's colours. On the tenant in the admin panel, **Colours** takes three: the ink (the sidebar, the text and the other dark surfaces), the accent (the active menu item, the brand mark) and the main button, which follows the accent when left empty. The shades that go with them, such as a hover, a tint and whether black or white goes on top, are worked out so the result stays readable. Empty is the default.

The sign-in pages, the admin panel and the mail keep the default colours.

## Settings and instructions

- **Review settings**, in the sidebar, choose the reviewers and their share, the model and the effort for every repository of the tenant. A reviewer can have a monthly budget, in the tenant's currency: once its reviews have cost that much across the tenant it is passed over for the rest of the month. Give the first reviewer a share and a budget and the second a share of 0 to use one until its budget is spent and the other after. A repository can set its own under **Review settings**.
- **Instructions** are what the review is told to look for: a method, the defect lenses and a guide per stack. The administrator keeps the defaults; a tenant can copy one and change it for its own repositories. An edit is a draft until it is published, and a repository on the `next` channel reads drafts, to try a change first.

## Reviews and cost

A tenant's panel opens on **Overview**: what needs attention (a repository that cannot start, high findings left open, runs that failed), the period's figures, reviews per day, findings by category and the repositories. **Reviews** lists the runs under the views *Needs action*, *Running* and *Failed or timed out*, and a review's own page shows every run on its pull request, the findings and what the run cost. Cost is what the reviewer reported, or an estimate from its tokens and the price list when it reported none.

*Findings accepted* counts what people said of the findings: on a review's own page, where each finding takes *Valid, will fix*, *Not a problem* or *Factually wrong*, and on the rating page. Only a valid finding counts as accepted. It says nothing until findings are answered.

On a repository, **Readiness** checks now whether it can be reviewed: that the connection can read it, that an AI provider is connected, that its runner is turned on, and whether the webhook ever delivered.

The admin panel opens on **Platform**: every tenant with its connections, repositories, reviews and the month's cost against its **monthly budget**, which is set on the tenant and stops nothing. A tenant reads its costs in US dollars or in euros, also set on the tenant, and its budget is in that currency. Its **time zone**, set there too and `Europe/Amsterdam` unless changed, is the one its times are shown in and its days are counted in. A review is costed in dollars; euros are converted on the page at the latest reference rate of the European Central Bank (`review:sync-exchange-rates`), so a past month reads at today's rate. Below it, **Capacity** says whether the installation has room: how long container reviews wait for a worker and how long they take, how often every worker is busy, and the server's processor, memory and disk. Its advice names what to raise: `REVIEW_CONTAINER_PROCESSES` when reviews wait and the server has room, the server itself when it has not. The figures come from a sample the scheduler takes each minute (`review:sample-capacity`), kept for 30 days, so they are empty until the scheduler runs.

### Trying a pull request

**Try a pull request** on the list of reviews takes the address of a Bitbucket pull request and reviews it without posting anything: no comment, no build status, no task. It is for comparing reviewers, models, efforts and instruction drafts on the same work, and the runs appear under the view *Trials*.

- The pull request may be merged already. It is then reviewed as it went in, against the destination branch as it was before the merge. One that was merged by fast-forward is refused, and one merged by rebase is reviewed on its last commit only: neither leaves a commit that says where the pull request began. A declined one is refused.
- Its repository need not be set up. It is added with *Review its pull requests* turned off, so nothing but a trial runs on it until someone turns that on. The workspace does need a source control connection.
- *Review as though nobody had* leaves out the comments and earlier findings on the pull request, so two runs start from the same place. Off, the run follows up on what is there.
- It needs the container runner, whatever runner the repository itself uses.

Each published review links to a page where anyone who can read the pull request can rate it.

## Adding a provider

A provider is a driver class, registered in `config/review.php`:

- source control: implement `App\Connections\SourceControlDriver`, and `App\SourceControl\SourceControl` for the repository calls. `SourceControl` documents the shape pull requests and comments are passed in.
- tickets: implement `App\Connections\TicketDriver`, which reads one ticket by its reference and returns it as markdown.
- AI providers: implement `App\Connections\LlmDriver`, which names the environment variables the reviewer reads the key from.

A driver declares its own credential and setting fields, so a new one shows up in the Connections form without further work.

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report a security problem privately, as [SECURITY.md](SECURITY.md) describes, not in a public issue.

## License

Review Central is open-source software licensed under the [MIT License](LICENSE).
