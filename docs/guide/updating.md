# Updating

Releases are tagged on GitHub, `v1.0.0` and up, and the [changelog](https://github.com/fruitcake/review-central/blob/main/CHANGELOG.md) says what changed in each and what to do about it. Deploy a tag for an installation you rely on; `main` has what is merged since.

After checking out a new release, on the server (and on every worker):

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate     # or restart your queue workers
```

Then look at **Admin → Setup**, which says what the release still needs:

- **This release has newer review instructions.** Under Review instructions, **Update from this release** shows per file what changes, as a diff. A file someone here edited is not picked, since taking the new text would undo that edit; read its diff and carry over what you want by hand. A tenant's own copy of a file is never touched.
- **The Worker is not up to date**, when Cloudflare sandboxes are set up: deploy it again from this release, `cd cloudflare/worker && npx wrangler deploy`. Then **Prepare the sandbox**, if the setup script changed.
- **The reviewer's image**, on Docker: rebuild it when `docker/reviewer` changed, `docker build --pull -t review-central/reviewer:latest docker/reviewer`.
