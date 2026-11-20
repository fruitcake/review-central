# Installation

Requires PHP 8.4 or newer with Composer, a database (MySQL 8, PostgreSQL 14 or SQLite), a cache that every process shares (the review lock lives in it) and a scheduler. Redis is recommended, for Horizon, the queue and the cache. The Docker runner also needs Docker and gVisor. Node is only needed to work on the docs or to deploy the Cloudflare Worker.

## With Laravel Forge

One server that runs everything, provisioned and deployed by Forge. Forge installs nginx, PHP, the database and Redis; only Docker and gVisor are installed by hand. The site runs as the `forge` user, or as a user of its own when the site is isolated. The steps below say `forge`: with isolation, read the site's user there, and run the `sudo` commands as `forge`, since an isolated user has no sudo. The names of Forge's screens change now and then, so the steps name what to set, not where each button is.

**1. The server**

Create an app server on Ubuntu 24.04 with PHP 8.4 or newer, MySQL (or PostgreSQL) and Redis. Keep the sudo password Forge shows once: step 5 needs it.

The review containers can reach anything on the server that listens on every interface, so check that MySQL and Redis only answer on `127.0.0.1`, or are closed by the firewall for the Docker network:

```sh
sudo ss -ltnp | grep -E '3306|5432|6379'
```

**2. The site**

Add a site for the public domain, for example `review.example.com`, with web directory `/public`, and install the repository `fruitcake/review-central` on the branch `stable`, which always holds the latest release. Turn on Forge's automatic deployment for it, and every release is deployed as it comes out; `main` has what is merged since, and is not for a server you rely on. Create the database and its user in Forge, and request a Let's Encrypt certificate for the site: the host only delivers webhooks over HTTPS.

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

`APP_URL` has to be the public address: it goes into every webhook, the links in a review and the redirect addresses of the sign-ins.

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

It imports the default review instructions, fetches the model prices and the exchange rates, asks for the name, email address and password of the first administrator, and creates a first tenant for them. It can be run again; each step is skipped once it is done.

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

Turn on Horizon for the site, or add a background process that runs `php artisan horizon` in the site's directory as `forge`. Set the time it may take to stop to 7300 seconds, so a review that is running is finished before Horizon stops: a job that [carries on with a new commit](./runners#carrying-on-with-a-new-commit) can take three reviews' time, two hours. With that turned off, 2700 seconds is enough. A process that was already running before step 5 has to be restarted once, to get the `docker` group.

Turn on the Laravel scheduler for the site, or add a scheduled job that runs `php artisan schedule:run` every minute as `forge`. The scheduler closes runs that never reported each hour; each night it refreshes the model prices and the exchange rates, prunes old logs and caches, and reads the open pull requests from their hosts again; it keeps Horizon's metrics every five minutes and samples the capacity each minute.

**7. Sign in**

`/admin` with the administrator from step 4. From there, [Setting up a tenant](./tenants).

## Without Forge

The same on a plain Ubuntu server: nginx with PHP-FPM on `public/`, MySQL and Redis on `127.0.0.1`, step 5 with the web server's user in place of `forge`, `php artisan horizon` under systemd or Supervisor with a stop timeout of 7300 seconds (`TimeoutStopSec` or `stopwaitsecs`), and `php artisan schedule:run` every minute from cron.

## The keys, and where each one goes

| Key | Made where | Kept where |
|---|---|---|
| `APP_KEY` | Forge, when it creates the site (`php artisan key:generate` elsewhere) | `.env` on the server. Encrypts everything below. |
| Source control token | GitHub: a fine-grained personal access token, of an account of its own (see [Setting up a tenant](./tenants)). Bitbucket: workspace settings, access tokens. Either has to read the repository, write pull requests and comments, set a status on a commit, and manage webhooks when the webhook is added for you. | A tenant's source control connection. |
| AI provider key | The provider's console: Anthropic, OpenAI, Gemini and the others. | A tenant's AI provider connection. |
| Ticket tracker token | Jira, Asana or GitHub. | A tenant's ticket connection. |
| Google sign-in, Asana and Jira apps | Optional, at Google, Asana and Atlassian: see [Setting up a tenant](./tenants#signing-in-with-google). | `.env`: `GOOGLE_*`, `ASANA_*`, `ATLASSIAN_*`. |
| Webhook secret | Made with the source control connection. | Shown under "Webhook" on that connection. "Add it for me" stores it with the host; by hand it is pasted into each repository's webhook. |

A connection's secrets are stored encrypted and never shown again; an edit that leaves one empty keeps it.

## Without Redis

For a try-out, the defaults of `.env.example` work: SQLite, and the database for the queue and the cache. Run `php artisan queue:work --queue=reviews,default --timeout=7260` in place of Horizon, and set `DB_QUEUE_RETRY_AFTER=7500`: a queued job that runs longer than that is started a second time. A review job's own timeout is the reviewer's limit plus ten minutes, `REVIEW_CONTAINER_TIMEOUT` + 600 seconds, times the reviews it may do in a row (three when it carries on with new commits).
