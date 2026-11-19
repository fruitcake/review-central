# Contributing

Thanks for helping to improve Review Central. Bug reports, fixes, new stack guides and new providers are all welcome.

## Before you start

- For a bug, open an issue with what you did, what you expected and what happened instead. Leave out keys, tokens and private code.
- For a larger change, such as a new runner, a provider or a change to how reviews are posted, open an issue first so we can agree on the approach before you build it.
- Security problems go through the private route in [SECURITY.md](SECURITY.md), not an issue.

## Setting up

Follow **Try it in ten minutes** in the README. In short:

```bash
composer setup               # .env, an app key, SQLite, the tables, the front end
php artisan review:install   # instructions, model prices, an admin account and a first tenant
php artisan serve
php artisan queue:work --queue=reviews,default   # in a second terminal
```

The diff runner works without Docker or Cloudflare, so most of the app can be run and tested locally with only an AI provider key.

## Checks

Every pull request must pass:

```bash
composer lint        # Pint, formats the code
composer test        # Pint check, PHPStan and the test suite
```

Tests run on SQLite in memory and need no services: HTTP calls to source control, ticket systems and AI providers are faked.

## Writing a change

- Add or update tests for what you change. A bug fix comes with a test that fails without it.
- Follow the style of the code around it. Comments explain why something is done, not what the next line does.
- Keep a pull request to one change, with a title and description that say what it does and why.
- Update the README when you change setup, configuration or behaviour someone relies on.
- New environment variables go in `.env.example`, with a comment on what they do.

## Adding a stack guide or a provider

- **Stack guides** live in `resources/review/skill/references` as `stack-<name>.md`. A guide names what to look for in that stack, with the reasoning, so the reviewer can judge rather than pattern-match. Cite official documentation where you can.
- **Providers** (source control, tickets, AI) are described under **Adding a provider** in the README.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
