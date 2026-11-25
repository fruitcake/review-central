# Contributing

Thanks for helping to improve Review Central. Bug reports, fixes, new stack guides and new providers are all welcome.

## Before you start

Everything happens on GitHub: [issues](https://github.com/fruitcake/review-central/issues) for bugs, questions and ideas, and pull requests against `main` for changes.

- For a bug, open an issue with what you did, what you expected and what happened instead. Leave out keys, tokens and private code.
- For a larger change, such as a new runner, a provider or a change to how reviews are posted, open an issue first so we can agree on the approach before you build it.
- Security problems go through the private route in [SECURITY.md](SECURITY.md), not an issue.

## Setting up

Follow the [quick start](https://review-central.com/guide/quick-start). In short:

```bash
composer setup               # .env, an app key, SQLite and the tables
php artisan review:install   # instructions, model prices, an admin account and a first tenant
php artisan serve
php artisan queue:work --queue=reviews,default   # in a second terminal
```

To look around with data, `php artisan migrate:fresh --seed --seeder=DemoSeeder` makes a team with a month of reviews; sign in as `demo@example.com` with the password `password`.

The diff runner works without Docker or Cloudflare, so most of the app can be run and tested locally with only an AI provider key.

## Checks

Format the code with `composer lint` (Pint), then make sure `composer test` passes; CI runs the same:

```bash
composer lint        # Pint, formats the code
composer test        # Pint check, PHPStan and the test suite
```

Tests run on SQLite in memory and need no services: HTTP calls to source control, ticket systems and AI providers are faked. `php artisan test --parallel` runs them faster. CI runs the checks on PHP 8.4 and 8.5.

## Writing a change

- Add or update tests for what you change. A bug fix comes with a test that fails without it.
- Follow the style of the code around it. Comments explain why something is done, not what the next line does.
- This repository is public. Use made-up data in tests, docs, commit messages and pull requests: names such as "Alex Author" and "Robin Reviewer", ticket references such as `DEV-123`, `LARAVEL-123` or `BACKEND-123`, `acme/shop` and `example.com`. Never real people's names, a client's ticket references or project names, or anything taken from a screenshot or live data.
- Keep a pull request to one change, with a title and description that say what it does and why.
- Update the docs in `docs/` when you change setup, configuration or behaviour someone relies on.
- New environment variables go in the [configuration reference](docs/guide/configuration.md), and in `.env.example` with a comment when a feature needs them set.
- Write the pull request's title for someone running Review Central: it goes into the release notes as it is. If they have to do something when updating that the release cannot tell from its files, such as press a button or set a setting a feature needs, add the label `upgrade` and say what in a paragraph of the description that starts with `**When updating:**`: that paragraph goes into the release notes. Deploying the Worker again, rebuilding the reviewer's image, new default instructions, migrations and new lines in `.env.example` are found by themselves.

## Adding a stack guide or a provider

- **Stack guides** live in `resources/review/skill/references` as `stack-<name>.md`. A guide names what to look for in that stack, with the reasoning, so the reviewer can judge rather than pattern-match. Cite official documentation where you can. Detecting the stack takes two more lines of code: see [Extending](https://review-central.com/guide/extending#adding-a-stack-guide).
- **Providers** (source control, tickets, AI) are described in [Extending](https://review-central.com/guide/extending).

## Releasing

For maintainers. Once, before the first release: make a deploy key that may move `stable`. GitHub does not let the workflow's own token push a branch forward over a change to a workflow, which most releases bring (Dependabot updates the actions weekly).

```bash
ssh-keygen -t ed25519 -N '' -C 'review-central release' -f stable-deploy-key
```

Add `stable-deploy-key.pub` under the repository's **Settings → Deploy keys**, with **Allow write access**, and the contents of `stable-deploy-key` as the Actions secret `STABLE_DEPLOY_KEY` under **Settings → Secrets and variables → Actions**. Then delete both files. If `stable` gets a branch rule, add **Deploy keys** to its bypass list.

A release is named after the day it comes out, `2026.11.19`, without leading zeros (`2027.1.5`). A second release on the same day gets a count: `2026.11.19.1`. Pushing the tag is the release; the `release` workflow does the rest:

```bash
git switch main && git pull
git tag 2026.11.19 && git push origin 2026.11.19
```

The workflow runs the checks on the tag and fast-forwards `stable` to it, which servers that deploy `stable` take from there. Then it makes a GitHub Release. Its notes start with **Updating to …**, a list of what to do, which `.github/release-notes.sh` reads from what changed since the last release and from the `**When updating:**` paragraph of each pull request labelled `upgrade`; run it locally to see what a release would say (`.github/release-notes.sh 2026.11.25`). Below that, GitHub writes the pull requests merged since, grouped by label as `.github/release.yml` says. A release that stopped halfway is finished with **Re-run failed jobs**: a step that was done is done again without changing anything. Edit a release's notes on GitHub afterwards to add anything the titles do not say.

A tag with a suffix, such as `2026.11.19-rc.1`, is released as a pre-release and leaves `stable` where it is.

The workflow refuses a tag that is not on `main` or not named after a day. Delete it (`git push origin :refs/tags/2026.11.19`) and tag again.

To roll back, move `stable` back to the previous release by hand, which a server deploying it then takes: `git push --force origin 2026.11.12^{commit}:refs/heads/stable`. Then release the fix as a new release; the workflow only ever moves `stable` forward.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
