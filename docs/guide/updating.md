# Updating

Releases are named after the day they came out, `2026.11.19`, and tagged on GitHub under that name. Each one's [release notes](https://github.com/fruitcake/review-central/releases) say what changed, with what to do about it first, under **Needs action when updating**. The branch `stable` always holds the latest release: deploy that, with your host's automatic deployment if you like, or a tag of your choice. `main` has what is merged since, and is not for a server you rely on.

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
