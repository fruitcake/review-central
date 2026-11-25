# Updating

Releases are named after the day they came out, `2026.11.19`, and tagged on GitHub under that name. Each one's [release notes](https://github.com/fruitcake/review-central/releases) start with **Updating to …**: what to do for that release beyond the steps below, such as deploying the Worker again or taking new review instructions. Then what changed. The branch `stable` always holds the latest release: deploy that, with your host's automatic deployment if you like, or a tag of your choice. `main` has what is merged since, and is not for a server you rely on.

After checking out a new release, on the server (and on every worker):

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate     # or restart your queue workers
```

Then look at **Admin → Setup**, which says what the release still needs:

- **This release has newer review instructions.** Under **Default instructions**, **Update from this release** shows per file what changes, as a diff. A file someone here edited is not picked, since taking the new text would undo that edit; read its diff and carry over what you want by hand. A tenant's own copy of a file is never touched.
- **The Worker is not up to date**, when Cloudflare sandboxes are set up: deploy it again from this release with **Deploy the Worker** on the same page, `php artisan review:deploy-worker`, or `cd cloudflare/worker && npx wrangler deploy`. Then **Prepare the sandbox**, if the setup script changed.
- **The reviewer's image**, on Docker: rebuild it when `docker/reviewer` changed, `docker build --pull -t review-central/reviewer:latest docker/reviewer`.
- **Webhook events**, when the release notes add one: open **Webhook** on each source control connection and press **Add it for me** again, which updates the hook that is there, or add the event by hand where the hook was made by hand. A GitHub App subscribes to its events in its own settings on GitHub. Then `php artisan review:sync-pull-requests` reads the open pull requests from their hosts again, for what the new events would have told.
