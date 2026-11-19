# Contributing

Review Central is open source under the MIT License, and contributions are welcome: bug reports, fixes, stack guides and new providers.

The [contributing guide](https://github.com/fruitcake/review-central/blob/main/CONTRIBUTING.md) has the details. In short:

- Open an issue first for a larger change, such as a new runner or provider, so the approach is agreed before it is built.
- Every pull request passes `composer lint` and `composer test`: Pint, PHPStan and the test suite, which need no services.
- To look around with data, seed a made-up team with a month of reviews:

```sh
php artisan migrate:fresh --seed --seeder=DemoSeeder
```

Then sign in as `demo@example.com` with the password `password`.

These docs are in the `docs` folder of the repository, built with [VitePress](https://vitepress.dev). Run `npm install && npm run dev` there to work on them.
