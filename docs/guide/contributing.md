# Contributing

Review Central is open source under the MIT License, and contributions are welcome: bug reports, fixes, stack guides and new providers.

The [contributing guide](https://github.com/fruitcake/review-central/blob/main/CONTRIBUTING.md) has the details. In short:

- Everything happens on GitHub: [issues](https://github.com/fruitcake/review-central/issues) for bugs, questions and ideas, pull requests against `main` for changes.
- Open an issue first for a larger change, such as a new runner or provider, so the approach is agreed before it is built.
- Format with `composer lint`, and make sure `composer test` passes: the Pint check, PHPStan and the test suite, which need no services. CI runs it on PHP 8.4 and 8.5.
- To look around with data, seed a made-up team with a month of reviews:

```sh
php artisan migrate:fresh --seed --seeder=DemoSeeder
```

Then sign in as `demo@example.com` with the password `password`.

These docs are in the `docs` folder of the repository, built with [VitePress](https://vitepress.dev). Run `npm install && npm run dev` there to work on them; this is the only place the project needs Node, besides deploying the Cloudflare Worker. Every page has an **Edit this page on GitHub** link at the bottom.
