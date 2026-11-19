# Quick start

Review a public pull request on GitHub from its diff, on your own machine. Nothing is cloned and no code is run: the review from the diff sends the pull request's diff to your AI provider. It needs PHP 8.4+, Composer and one AI provider's key: Anthropic, OpenAI, Gemini, Mistral, Groq, DeepSeek, xAI or OpenRouter.

```sh
git clone https://github.com/fruitcake/review-central.git && cd review-central
composer setup               # .env, an app key, SQLite and the tables
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

- **A reviewer that reads the repository** (Claude Code, Codex, OpenCodeReview, Pi) needs a sandbox for the pull request's code: [Docker on your server](./runners#a-container-on-this-server), or [Cloudflare sandboxes](./runners#a-cloudflare-sandbox) (experimental), which can also run on this machine with `npx wrangler dev` and your own Docker.
- **Reviews posted on your pull requests** need a source control connection with a token, GitHub or Bitbucket Cloud, and the webhook it shows. See [Setting up a tenant](./tenants).
- **Admin → Setup** says what is in place, and what to do about what is not.

The review from the diff is the default runner until another is set up and picked under Admin → Settings. It is the safe one: nothing of the pull request runs. It is also the weaker one, since it sees the diff and not the code around it.

For a server that reviews every pull request, continue with [Installation](./installation).
