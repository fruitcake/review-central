# Security

Review Central holds credentials for source control, ticket trackers and AI providers, and its reviewer reads code, and text, that other people wrote. This page says what it does to keep those apart, what it does not do, and how to run it safely. To report a vulnerability, see the [security policy](https://github.com/fruitcake/review-central/blob/main/SECURITY.md).

## Which runner

For a review that reads the code around a change, **use the Cloudflare sandbox**. It is the runner with the strongest isolation:

| | `cloudflare` (recommended) | `docker` | `diff` |
|---|---|---|---|
| **Where the pull request's code is** | A microVM of its own on Cloudflare, started for one review and destroyed after it | A throwaway container under gVisor, on the machine that runs the reviews | Nowhere: only the diff is read, and nothing runs |
| **The AI provider's key** | Never in the sandbox: it holds a placeholder, and the Worker puts the key in only on a request over HTTPS to that provider's own host ([how](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md#the-ai-providers-keys)) | In the container, readable by the reviewer: the key of the provider that runs it, no other | Sent from this server, never near the code |
| **Your server and network** | Not on it: the sandbox runs on Cloudflare, and reaches your server only as anyone on the internet does | Next to it: the container is on a Docker network of this machine (see [what it does not do](#what-it-does-not-do)) | Not involved |
| **What leaves your server** | The checkout, into the sandbox through your bucket, and what the reviewer sends to the AI provider | What the reviewer sends to the AI provider | The diff, the pull request's title and description, its ticket, its comments and earlier findings, sent to the AI provider |
| **The review** | Full: the reviewer reads the checkout and `vendor/` | Full | Lighter: it sees only what the diff shows |

Docker is for your own repositories on a machine you give it alone; the diff runner for a first try, or where no code should run at all. The platform's runner is picked under **Admin → Settings**, and a repository can pick its own under **Runs on**.

## What it does

**Around the code under review**

- The pull request's code runs only in the reviewer's sandbox. The worker decides whether to review, writes what the reviewer reads and files its report without running any of it.
- No runner starts a server from the checkout or runs Composer scripts or plugins: `composer install` runs with `--no-scripts --no-plugins`.
- The reviewer never gets a credential for the repository's host or for this server, and it starts without any variable named like a secret except its own provider's key.
- The reviewer's CLI runs with the review's own settings, not the pull request's: `.claude/settings.json`, `.claude/settings.local.json`, `.codex/` and `.pi/` are removed from the checkout before it starts, and Claude Code runs with every hook off. A pull request's skills and `CLAUDE.md` are still read, as text.
- The review step gets only the keys of the reviewer that runs. Pi on a trial, which reviews with whichever provider there is, gets them all.
- Of a cached `vendor/` archive only `vendor/` is unpacked, so an archive written from a sandbox cannot put files in another review's checkout.
- Git on the worker runs with hooks, the filesystem monitor and the `file` protocol off, without submodules, external diff or text conversion, and with the host's credentials in its environment rather than in the clone.

**Around what it posts**

- Everything posted on the host, the review's log and the public rating page first have the tenant's credentials taken out, also spaced out, reversed, in base64 or in hex.
- On a public repository, a GitHub App's check shows only the review's own steps, not the reviewer's words.
- Markdown from a pull request, a ticket or the reviewer is shown without HTML, and only `http` and `https` links are followed.

**Around who may start what**

- Webhooks are checked against the connection's own secret, or the GitHub App's.
- `/review` can be limited to the tenant's trusted accounts or turned off, and a repository can review only the trusted accounts' pull requests by itself, so anyone else's waits for a member.
- A pull request from a fork is not reviewed by itself unless the tenant turns that on.
- A GitHub App's installation is connected only by an owner of the organisation, or the account itself.

**Around the installation**

- Every stored token and key is encrypted with `APP_KEY`.
- A gateway or tracker address a tenant enters must be HTTPS on the public internet, and a redirect from it is not followed.
- Signing in with Google needs an address Google has verified, and joins a tenant only on the organisation Google names.
- Tenants are kept apart in every page, action and live update; an administrator can open every tenant.
- Within a tenant, only its admins manage connections and their keys, apps, repositories, review settings, instructions and members. A member reads the reviews, rates their findings, asks the reviewer and starts a review.

## What it does not do

These are known limits. Plan around them, and see [Running it safely](#running-it-safely).

- **It does not stop prompt injection.** The reviewer reads text anyone can write: the change, and on a public repository every comment on the pull request. It can be talked into writing something misleading in its review, or, in Docker, into reading its key. The secret filter is a last check and no boundary: a key cut in pieces or encoded otherwise still passes.
- **It does not limit the Docker container's network.** The reviewer's container is on Docker's `bridge` network by default (`REVIEW_CONTAINER_NETWORK`): it reaches the internet, and also this machine and what it can reach, such as a cloud provider's metadata address. gVisor isolates the kernel, not the network. The Cloudflare sandbox has neither problem.
- **It does not tell trusted accounts by ID alone.** A trusted account is matched by its account ID or by the name it signs in with. A GitHub login can be made of digits, so list a GitHub account by its login rather than its numeric ID; Bitbucket nicknames are not guaranteed to be unique.
- **It does not check an address again when it is used.** A gateway or tracker address is checked to be public when it is saved; a name that later points elsewhere is not checked again.
- **It does not keep code from your AI provider.** Every runner sends the pull request, and with a container runner whatever the reviewer reads, to the provider you chose.

## Running it safely

- **Run reviews in the Cloudflare sandbox.** Above all for repositories with outside contributors, and for any public one.
- **Where you use Docker, give it a machine of its own.** Starting containers is as good as root on that machine. Keep MySQL and Redis on `127.0.0.1`, or closed to the Docker network, and point `REVIEW_CONTAINER_NETWORK` at a network that reaches only the AI providers if you can.
- **Use gVisor or another sandboxing runtime** with Docker. Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
- **Treat a key used in Docker as readable by the reviewer.** Give each tenant a key of its own with a spending limit at the provider, and replace it when in doubt.
- **Give tokens the narrowest scopes** the connection asks for: **How to get this** next to each field lists them.
- **Decide who may comment `/review`.** Every review spends the tenant's AI budget, and by default anyone who can comment on a pull request can start one. On a repository that outside contributors can comment on, list the team's accounts under **Review settings → Trusted accounts** and let only them, or turn the command off. On a public repository, set *Whose pull requests are reviewed by themselves* to the trusted accounts: anyone else's pull request then waits under **Pull requests** for a member to start it.
- **Leave pull requests from forks off** on a public repository unless you mean it. Turned on, anyone who opens a pull request from a fork has their code run in the sandbox with the tenant's AI key, on every push. Off (the default), a member starts such a review on purpose.
- **Make admin only who should manage the tenant's credentials.** Everyone else can be a member: they see no key, and change no setting.
- **Back up `APP_KEY`** apart from the database: it encrypts every stored token and key, and without it they cannot be read again.
- **Serve over HTTPS** with `APP_DEBUG=false`. **Admin → Setup** checks both.
