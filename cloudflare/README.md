# Reviews in a Cloudflare sandbox

> [!NOTE]
> Cloudflare's Sandbox SDK and its snapshots are in beta at Cloudflare. This is the safest runner that reads the code: see [Security](https://review-central.com/guide/security#which-runner).

A container review can run its reviewer in a [Cloudflare sandbox](https://developers.cloudflare.com/sandbox/) instead of in Docker on this installation's worker. A sandbox is a microVM of its own, started for one review and destroyed after it. Many can run side by side, whatever the worker's own size.

The worker still does the rest: it clones the repository, decides what to review, and files the report. Only the reviewer's step moves.

```
review-central worker                Cloudflare
---------------------                ----------
clone, precheck
pack checkout + scripts + home  -->  R2 bucket
POST /runs/{name}               -->  Worker -> Durable Object -> sandbox
read the output as it streams   <--    install tools, run the reviewer
                                       pack home + ai-review.json  --> R2 bucket
fetch the archive               <--  R2 bucket
file the report                      sandbox destroyed
```

Reports of how it went, good or bad, are welcome as [GitHub issues](https://github.com/fruitcake/review-central/issues).

## What you need

- A Cloudflare account on the **Workers Paid** plan ($5 a month). Containers are not on the free plan.
- Node 20 or later on the machine you deploy from. No Docker, and no image to build or publish: see "The image" below.

## Deploy it from review-central

The usual way: review-central deploys it itself, from the machine that runs its reviews' queue (Node 20 or later).

1. In the Cloudflare dashboard, [create an API token](https://dash.cloudflare.com/profile/api-tokens) for the account with **Workers Scripts**, **Containers** and **Workers R2 Storage**: Edit, and **Account Settings**: Read. For the bucket's own keys: R2 → Manage API Tokens → Create Account API token, with Object Read & Write on the bucket.
2. Under **Admin → Settings → Cloudflare sandboxes**, set the account ID, that token, and the bucket's keys. The Worker's name and the bucket's default to `review-central-sandbox` and `review-central`.
3. On **Admin → Setup**, press **Deploy the Worker**. On the reviews' queue it installs Wrangler, makes the bucket when it is not there, deploys the Worker with those names, and gives it a token it makes. The Worker's address and that token are kept under Settings; the setup page shows how the deploy went, with what Wrangler printed.

Then add the bucket's two lifecycle rules, as in [step 1](#1-the-bucket); a deploy makes the bucket but sets no rules.

Press it again after an update: the setup page says when the Worker runs an older version. The same from a shell is `php artisan review:deploy-worker`, which also takes `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` from the environment. What `.env` sets always goes before what is under Settings.

## Or by hand

The same in three steps, from a checkout of this repository, and the way to [run it on your own machine](#run-it-on-your-own-machine).

## 1. The bucket

Create an R2 bucket. The name `review-central` is what `worker/wrangler.jsonc` expects; change it there if you pick another.

```sh
cd cloudflare/worker
npm install
npx wrangler login
npx wrangler r2 bucket create review-central
```

Then an API token for review-central to reach it: in the Cloudflare dashboard, **R2 → Manage API Tokens → Create Account API token**, with **Object Read & Write** on this bucket only. It shows an access key id, a secret access key and the S3 endpoint (`https://<account id>.r2.cloudflarestorage.com`). Keep all three for step 3.

Add two lifecycle rules:

- objects under `review/sandbox/` deleted after a day. review-central removes them itself after each review; the rule is for what a crashed worker leaves.
- objects under `review/cache/` deleted after 14 days: each repository's `vendor/`. One that expires is installed and kept again by the next review of a pull request whose lock is the destination branch's.

Not under `review/snapshots/`, where the Worker notes its snapshot.

## 2. The Worker

Pick a long random token. It is the only thing between the internet and a machine that runs commands, so treat it as a password.

```sh
openssl rand -hex 32          # the token
npx wrangler secret put API_TOKEN
npx wrangler deploy
```

`deploy` prints the Worker's address, such as `https://review-central-sandbox.<you>.workers.dev`. Check it:

```sh
curl -H "Authorization: Bearer <token>" https://review-central-sandbox.<you>.workers.dev/health
# {"ok":true,"version":"…","bucket":true}
```

## 3. review-central

In `.env` on the server, and on every worker:

```dotenv
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=review-central
R2_ENDPOINT=https://<account id>.r2.cloudflarestorage.com

REVIEW_CLOUDFLARE_URL=https://review-central-sandbox.<you>.workers.dev
REVIEW_CLOUDFLARE_TOKEN=<token>
```

Then `php artisan migrate`, `php artisan config:cache` if you cache it, and `php artisan horizon:terminate`.

"A Cloudflare sandbox" is now a runner: for every repository under Admin → Settings, for one repository under its "Runs on", or for one trial under "Try without posting". The review's page shows where it ran, for how long, and about what that cost.

## Run it on your own machine

To try a change to the Worker, or a review in a sandbox, without deploying anything. `wrangler dev` runs the Worker locally and its sandboxes in your own Docker; its bucket is a stand-in it keeps under `.wrangler/`. No Cloudflare account is needed for this.

```sh
cd cloudflare/worker
npm install
cp .dev.vars.example .dev.vars     # API_TOKEN=local
npx wrangler dev                   # http://127.0.0.1:8787
```

In review-central's `.env`, leave `REVIEW_CLOUDFLARE_URL` and `REVIEW_CLOUDFLARE_TOKEN` empty and set:

```dotenv
REVIEW_CLOUDFLARE_LOCAL=true
```

It is off unless set. "A Cloudflare sandbox" as runner then asks the Worker on this machine. Since no disk here can reach that stand-in bucket, the archives go through the Worker (`/files/…`) instead; a deployed Worker is never used that way. Once `REVIEW_CLOUDFLARE_URL` and `REVIEW_CLOUDFLARE_TOKEN` are set, the deployed Worker is the one that is asked, whatever this says.

What it shows and what it does not: the whole path through the Worker, the setup script and the reviewer run for real. Docker on your machine stands in for the microVM, and Cloudflare's own image needs Wrangler 4.141 or later to be pulled locally. Snapshots may behave differently than deployed.

## Settings

| Variable | Default | What it does |
| --- | --- | --- |
| `REVIEW_CLOUDFLARE_INSTANCE` | `standard-1` | The sandbox's size: `lite`, `basic`, `standard-1` … `standard-4`. A review typically uses 230–550 MB, so `basic` (1 GiB) may do. Also under Settings → Cloudflare sandboxes. The capacity page advises one from what the reviews measured: their memory at most, and how often the steps around the model were short of processor. |
| `REVIEW_CLOUDFLARE_SETUP` | `true` | Installs the tools in a fresh sandbox and starts later ones from a snapshot of it. Turn it off with an image of your own (below). |
| `REVIEW_CLOUDFLARE_PROXY_KEYS` | `true` | Keeps the AI providers' keys in the Worker (see below). Off only for a reviewer that will not trust the Worker's certificate. |
| `REVIEW_CLOUDFLARE_DISK` | `r2` | The filesystem disk on the bucket the Worker is bound to. |
| `REVIEW_CLOUDFLARE_LOCAL` | `false` | Uses the Worker on this machine (`npx wrangler dev`) while no deployed one is named. |
| `REVIEW_CLOUDFLARE_LOCAL_URL` | `http://127.0.0.1:8787` | Where that local Worker listens. |
| `REVIEW_CONTAINER_LOG_DISK` | empty | Also copies each run's log to this disk, for a web server that is not the worker. Not needed for sandboxes: their output is streamed to the worker like Docker's. |
| `REVIEW_CONTAINER_SESSION_DISK` | empty | Also keeps each pull request's session on this disk, so any worker can resume it. |
| `REVIEW_CLOUDFLARE_RATE_VCPU`, `_MEMORY`, `_DISK` | Cloudflare's list prices | Dollars per vCPU-second, GiB-second and GB-second, for the estimate. |

## What it costs

Cloudflare charges for the memory and disk of an instance while it runs, and for the processor it uses. review-central does not get a bill per sandbox from Cloudflare, so it estimates: the instance's size, times the seconds it ran, times the rates above, counting the processor as fully used. That is the most it can be. Five minutes on `standard-1` comes to about half a cent. The Workers plan's included usage, the Worker's own requests and R2 are not in the estimate.

The estimate is kept per review (`review_runs.sandbox_cost`) and shown on its page; the month's total per tenant is on the overview, next to the AI providers' cost.

## The AI providers' keys

The sandbox never gets them. review-central sends each key to the Worker beside the run, with the host it is for (`api.anthropic.com`, `api.openai.com`, a gateway's own host when one is set), and gives the reviewer a random placeholder in its place. Before the sandbox starts, the Worker has Cloudflare hand its HTTPS to those hosts to `KeyProxy`, which checks the request is for that host over HTTPS, puts the key in where a provider reads one (`Authorization`, `x-api-key`, `x-goog-api-key`, `api-key`, or the `key` parameter) when that holds exactly the placeholder, and sends it on, without following a redirect. Whatever the reviewer reads or is talked into, it cannot print or send a key it does not have.

That interception answers for those hosts with a certificate of Cloudflare's, which the Worker adds to the sandbox's trust store before the reviewer runs; the reviewer also gets `NODE_EXTRA_CA_CERTS` for Node and `SSL_CERT_FILE` for the rest. A gateway over plain HTTP stops the review instead: the Worker would send its key on unencrypted, and the key never goes into the sandbox.

Other traffic leaves the sandbox as it did: Composer, npm and the CLIs' own updates still reach the internet, as they do from a CI pipeline that runs the same pull request. So the sandbox keeps the keys out of reach, not the code: what it could send out is the checkout it was given, which whoever opened the pull request can read already. A reviewer that is talked into it can still spend the key through the Worker for as long as the review runs, within its budget (**Budget per review** in the review settings, $10 by default) and its time limit.

## What differs from Docker

- **The first review installs the tools**, which takes a few minutes; the ones after it start from the snapshot. The reviewer's own CLI (Claude Code, Codex, OpenCodeReview, Pi) is installed by its script when the image has none.
- **PHP 8.1 to 8.5**, where the reviewer's Docker image goes back to 5.6. An older repository is reviewed on the newest; Composer runs with `--ignore-platform-reqs`, as it does on Docker.
- **The Composer cache is the destination branch's `vendor/`**, one per repository, in the bucket under `review/cache/<repository>/`. A review fetches it, and Composer installs only what the pull request's lock changes. It is kept again only by a review whose lock is the destination branch's, written right after the install, so what is kept is never a pull request's own packages. Composer's own download cache does not travel.
- **The session travels, the caches do not.** A follow-up review takes the reviewer's session along (a few MB), not the CLIs and caches in its home (`.local`, `.npm`, `.tools`, `.cache`, `.composer`, together hundreds of MB): the sandbox installs a newer CLI than its snapshot has itself, in seconds.
- **Memory and processor are measured per step**, as on Docker: the review's page and the capacity section on Platform show them.
- **The reviewer's key is not in the sandbox**, where it is in the Docker container: the Worker puts it in on the way to the provider (above). The sandbox has the internet and no credential for the repository's host.

## The image

A sandbox cannot start from a published image on Docker Hub, such as a ready-made PHP image: Cloudflare starts a sandbox from its own Debian image (with Node 24) or from an image in its own registry. So that nobody has to build and publish one, the tools are installed once and kept:

1. A sandbox of its own, one per version of the setup script, starts from Cloudflare's image and runs `resources/review/skill/sandbox-setup.sh`: PHP 8.1 to 8.5, Composer, git, and the CLIs of Claude Code, Codex, OpenCodeReview and Pi. That takes a few minutes, once. **Prepare the sandbox** on the admin's Setup page has it done ahead; otherwise the first review asks for it, and every review that starts meanwhile waits for the same one rather than installing its own.
2. The Worker takes a snapshot of that sandbox, which never held a checkout, and notes it in the bucket under `review/snapshots/`, with the setup's output beside it (`<version>.log`).
3. Every review starts from the snapshot, with everything in place.

A change to the setup script makes a new snapshot at the next review. Cloudflare keeps a snapshot for 30 days after it was last started from, so one that is used stays. Snapshots are a public beta at Cloudflare, and free during it.

The snapshot has PHP 8.1 to 8.5 side by side, as the reviewer's Docker image has, and a review uses the one its `composer.json` asks for. One snapshot serves every version; a snapshot per version would only be worth it if they grew too large for one, and a sandbox's disk is 8 GB on `standard-1`.

### An image of your own

For PHP before 8.1, or the very image Docker runs, the reviewer's own image can be used instead. It has to be pushed to Cloudflare's registry, from a machine with Docker:

1. Build the reviewer's image, from the repository's root: `docker build --pull -t review-central/reviewer:latest docker/reviewer`
2. In `worker/wrangler.jsonc`, name `worker/Dockerfile`, which builds on it:

   ```jsonc
   "containers": [{
     "class_name": "ReviewSandbox",
     "scheduling_policy": "durable_object",
     "images": { "reviewer": { "dockerfile": "./Dockerfile" } }
   }]
   ```
3. `npx wrangler deploy`, which builds and pushes it.
4. Set `REVIEW_CLOUDFLARE_SETUP=false` in review-central's `.env`.

Do this again after the reviewer's image changes.

## When something is wrong

- **The setup page says the Worker is not up to date**: this installation has newer Worker code than the one deployed. Deploy it again: **Deploy the Worker** on the setup page, `php artisan review:deploy-worker`, or from a checkout of the same version `cd cloudflare/worker && npx wrangler deploy`. A change to `src/index.ts` raises `WORKER_VERSION` in it, which is what the page compares.
- **`wrangler deploy` refuses the containers block for having no image**: the block names no image on purpose, since the Worker starts Cloudflare's own (`cloudflare/debian-trixie`). If your Wrangler wants one all the same, use "An image of your own" above; nothing else changes.
- **401 from the Worker**: `REVIEW_CLOUDFLARE_TOKEN` is not the secret the Worker has. `npx wrangler secret put API_TOKEN` again.
- **"Nothing in the bucket at …"**: the Worker's bucket is not the one the `r2` disk writes to. Compare `bucket_name` in `wrangler.jsonc` with `R2_BUCKET`.
- **"The sandbox did not start within a minute"**: look at the Worker's logs, `npx wrangler tail`. A first start after a deploy pulls the image and can be slow; try once more.
- **The review stops with exit code 124**: it ran longer than `REVIEW_CONTAINER_TIMEOUT`.
- **A sandbox left running**: review-central tells the Worker to destroy it after every step, also one that failed. One that survives that stops by itself a quarter of an hour after its review's time limit.
