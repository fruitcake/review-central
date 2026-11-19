# Security

Review Central holds credentials for source control, ticket systems and AI providers, and runs code from the repositories it reviews. We take reports about it seriously.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately through GitHub instead: on the repository's **Security** tab, choose **Report a vulnerability**.

Include what you found, how to reproduce it, and what an attacker could do with it. We will acknowledge the report within a few working days, keep you informed while we work on a fix, and credit you in the release notes unless you prefer otherwise.

## Supported versions

Only the latest release receives security fixes; they are released as soon as they are ready, and named in the [release notes](https://github.com/fruitcake/review-central/releases). Keep your installation up to date as described in [Updating](https://review-central.com/guide/updating).

## What is in scope

Of particular interest:

- a tenant reading or changing another tenant's repositories, reviews, connections or settings;
- credentials (API keys, OAuth tokens, webhook secrets) leaking into logs, pages, review comments or the sandbox beyond what the review needs;
- code in a reviewed pull request escaping its sandbox, or reaching the server's network or secrets;
- forged webhooks being accepted, or a reviewed pull request changing what its review reports or where it is posted;
- cross-site scripting through review output, pull request content or uploaded logos.

A review that is wrong or misses an issue is not a security problem; please open a normal issue for that.

## Running it safely

- Pick the runner that fits the code. The diff runner runs nothing and clones nothing: it sends the pull request's diff, title and description, its ticket, its comments and earlier findings to the AI provider. The Docker (preferably with gVisor) and Cloudflare runners give the reviewer the whole checkout, inside a sandbox. See [Where a review runs](https://review-central.com/guide/runners) and [Security](https://review-central.com/guide/security) in the docs.
- Give source control and ticket credentials the narrowest scopes the setup page asks for.
- Serve the panel over HTTPS only, and keep `APP_DEBUG=false` in production.
