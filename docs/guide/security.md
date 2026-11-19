# Security

Review Central holds credentials for source control, ticket trackers and AI providers, and the reviewer reads code from the repositories it reviews. This page is about running it safely; to report a vulnerability, see the [security policy](https://github.com/fruitcake/review-central/blob/main/SECURITY.md).

## What goes where

| Runner | What leaves your server | What runs the pull request's code |
|---|---|---|
| `diff` | The diff, the pull request's title and description, its ticket, its comments and earlier findings, sent to your AI provider. | Nothing. |
| `docker` | What the reviewer sends to your AI provider while it reads the checkout. | A throwaway container under gVisor, holding only the AI provider key. |
| `cloudflare` | The checkout, into a Cloudflare sandbox, and what the reviewer sends to your AI provider. | The sandbox, holding only the AI provider key. |

No runner starts a server from the checkout or runs Composer scripts or plugins. The reviewer never gets a credential for the repository's host or for this server.

## Running it safely

- **Pick the runner that fits the code.** The diff runner is the safe default. Docker and Cloudflare give a better review, because the reviewer reads the code around a change, and they isolate it.
- **Give the container runner a machine of its own.** Starting containers is as good as root on that machine. Keep MySQL and Redis on `127.0.0.1`, or closed to the Docker network.
- **Use gVisor or another sandboxing runtime.** Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
- **Give tokens the narrowest scopes** the connection asks for.
- **Back up `APP_KEY`** apart from the database: it encrypts every stored token and key, and without it they cannot be read again.
- **Serve over HTTPS** with `APP_DEBUG=false`. **Admin → Setup** checks both.
