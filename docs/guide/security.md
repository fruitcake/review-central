# Security

Review Central holds credentials for source control, ticket trackers and AI providers, and the reviewer reads code from the repositories it reviews. This page is about running it safely; to report a vulnerability, see the [security policy](https://github.com/fruitcake/review-central/blob/main/SECURITY.md).

## What goes where

| Runner | What leaves your server | What runs the pull request's code |
|---|---|---|
| `diff` | The diff, the pull request's title and description, its ticket, its comments and earlier findings, sent to your AI provider. | Nothing. |
| `docker` | What the reviewer sends to your AI provider while it reads the checkout. | A throwaway container under gVisor, holding the tenant's AI provider keys. |
| `cloudflare` | The checkout, into a Cloudflare sandbox, and what the reviewer sends to your AI provider. | The sandbox, holding placeholders for the AI provider keys: the Worker puts the keys in on the way to the provider, so the reviewer never has them. |

No runner starts a server from the checkout or runs Composer scripts or plugins. The reviewer never gets a credential for the repository's host or for this server.

The reviewer's CLI runs with the review's own settings, not the pull request's: `.claude/settings.json`, `.claude/settings.local.json`, `.codex/` and `.pi/` are removed from the checkout before it starts, and Claude Code runs with every hook off. A pull request's skills and `CLAUDE.md` are still read, as text. The review step gets only the keys of the reviewer that runs; Pi on a trial, which reviews with whichever provider there is, gets them all. Of a cached `vendor/` archive only `vendor/` is unpacked.

## Running it safely

- **Pick the runner that fits the code.** The diff runner is the safe default. Docker and Cloudflare give a better review, because the reviewer reads the code around a change, and they isolate it.
- **Give the container runner a machine of its own.** Starting containers is as good as root on that machine. Keep MySQL and Redis on `127.0.0.1`, or closed to the Docker network.
- **Use gVisor or another sandboxing runtime.** Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
- **Give tokens the narrowest scopes** the connection asks for.
- **Decide who may comment `/review`.** Every review spends the tenant's AI budget, and by default anyone who can comment on a pull request can start one. On a repository that outside contributors can comment on, list the team's accounts under **Review settings → Trusted accounts** and let only them, or turn the command off. On a public repository, set *Whose pull requests are reviewed by themselves* to the trusted accounts: anyone else's pull request then waits under **Pull requests** for a member to start it.
- **Leave pull requests from forks off** on a public repository unless you mean it. Turned on, anyone who opens a pull request from a fork has their code run in the sandbox with the tenant's AI key, on every push. Off (the default), a member starts such a review on purpose.
- **Prefer the Cloudflare sandbox for code you do not trust.** There the keys never enter the sandbox ([how](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md#the-ai-providers-keys)). In Docker they do.
- **In Docker, treat the AI keys as readable by the reviewer.** It reads text anyone can write: the change, and on a public repository every comment on the pull request. Talked into it, it can reach the keys in its sandbox, which has the internet. Everything posted on the host, and the review's log, first has the tenant's credentials taken out, also spaced out, reversed, in base64 or in hex; a key cut in pieces or encoded otherwise still passes, so that is a last check and no boundary. Give each tenant a key of its own with a spending limit at the provider, and replace it when in doubt.
- **Everyone in a tenant can manage it.** Its members can add and change connections and their credentials, see the webhook secret and change the review settings. Invite only people you would trust with those.
- **Back up `APP_KEY`** apart from the database: it encrypts every stored token and key, and without it they cannot be read again.
- **Serve over HTTPS** with `APP_DEBUG=false`. **Admin → Setup** checks both.
