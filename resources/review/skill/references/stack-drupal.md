---
paths:
  - '**/*.{php,module,install,theme,inc,profile,engine,yml,twig}'
---

# Drupal

Drupal 10 and 11. Custom code lives in `web/modules/custom/` and `web/themes/custom/` (or under `docroot/`), configuration in the config sync directory (usually `config/sync/`), and core and contributed code is installed by Composer.

## Files OCR skips are still code

`.module`, `.install`, `.theme`, `.inc` and `.twig` are not on OpenCodeReview's language list, so `ocr delegate preview` reports them as excluded. In a Drupal repository they hold hooks, update functions and templates. Review them as you would PHP: get their diff from `git diff` yourself.

## Drupal's own skills

When the repository commits the agent skills of `drupal/ai_best_practices` (the drupal.org package; they install under `.agents/skills/drupal-*`, and the review's `composer install`, without dev packages and plugins, never puts them there itself): read `drupal-render-pipeline` before judging render arrays, cache metadata or lazy builders; `drupal-configuration` for config, schema and update hooks; `drupal-automated-testing` when weighing test coverage. Where they and this guide disagree, they are the more specific.

## What the step gives you

Core and contributed projects are installed when `composer install` succeeded, but the review runs Composer without plugins, so `installer-paths` are not applied: core is in `vendor/drupal/core/` and a contributed module in `vendor/drupal/<project>/`, not under `web/`. Read core there before judging an API. There is no database and no `settings.php` with credentials, so `drush` cannot run.

## Where the wiring lives

- **Hooks** in `<module>.module` are `<module>_<hook>()`. A function named after another module, or after the file instead of the machine name in `<module>.info.yml`, never runs. On Drupal 11.1+, hook classes in `src/Hook/` use `#[Hook('...')]`.
- **Routes** (`<module>.routing.yml`): the `_controller` class and method, or `_form` class, exist. Every route needs requirements (see Security).
- **Services** (`<module>.services.yml`): the class exists, its constructor takes the `arguments` in that order, and event subscribers are tagged `event_subscriber`.
- **Plugins** are found by namespace: a block in `src/Plugin/Block/` with a `#[Block]` attribute or `@Block` annotation. One in the wrong directory or namespace is never discovered.
- **Config in `config/install/`** is imported only when the module is installed. Changing it does nothing on a site where the module is already enabled; that needs a `hook_update_N()` or `hook_post_update_NAME()`, or a change in the sync directory. New config keys need `config/schema/` entries, or strict schema checks in tests fail.
- **Updates.** `hook_update_N()` and post-update functions run once per site. Editing one that already ran does nothing there; renumbering it runs it again. An update over many entities uses `$sandbox` batching.
- **Libraries.** `#attached` and `{{ attach_library() }}` name `module/library` entries that exist in `*.libraries.yml`.
- **Settings overrides.** `$config['name']['key']` in `settings.php` beats the sync directory: a config change it overrides does nothing.

## Security

- **Route access.** `_permission`, `_role`, `_entity_access`, `_custom_access` or `_access: 'TRUE'`. The last makes the route public: a finding when the route changes state or returns data an anonymous visitor should not see. A GET route that changes state needs `_csrf_token: 'TRUE'`, and links to it have to be built with `Url` so they carry the token.
- **Entity queries** call `->accessCheck()` explicitly; Drupal 10 throws without it. `accessCheck(FALSE)` on a query whose results reach a user bypasses node and entity access. That is a finding unless the code checks access on each result.
- **Cacheable access results.** `AccessResult::allowedIf($account->id() == $owner)` without `->cachePerUser()` (or `addCacheableDependency($account)`) is cached and reused for the next user. The same goes for render arrays: per-user or per-role output without `#cache` `contexts` (`user`, `user.roles`, `url.query_args`) is served to everyone who hits the cache. Anonymous visitors get whole pages from Page Cache, which ignores contexts. Personalised output for them needs a lazy builder or the kill switch.
- **Output.** Twig autoescapes; `|raw` on user data is XSS. `#markup` is filtered by `Xss::filterAdmin()`, which still allows a lot; use `#plain_text` for text. In `t()` and `FormattableMarkup`, `@var` and `%var` escape and `:var` is for URLs. A string concatenated into `t()` escapes nothing and cannot be translated.
- **Database API.** `$connection->query()` with a variable in the SQL string. Placeholders, `->condition()` and `$connection->escapeLike()` are safe.
- **Forms.** Form API adds a CSRF token for authenticated users. Access to a form element is `#access`. File uploads set upload validators (allowed extensions, size).
- **Permissions** that grant something dangerous are marked `restrict access: true` in `*.permissions.yml`.

## Performance

- `Node::load()` or `$storage->load()` per item in a loop; `loadMultiple()` replaces it.
- An entity query with no `->range()` or pager on a table that grows.
- Work in hooks that run on every request or entity: `hook_entity_load`, `hook_page_attachments`, `hook_preprocess_*`, `hook_entity_view` doing queries or HTTP calls.
- `'#cache' => ['max-age' => 0]` bubbles up and disables caching for the whole page.
- Missing cache tags show stale content (correctness, not performance): output that depends on an entity carries that entity's tags.

## Correctness

- `$entity->get('field')->value` on an empty field is null; `->isEmpty()` checks it. Reference fields use `->target_id` or `->entity`, which can be null for a deleted target.
- Translations: an entity loads in its original language. Use `getTranslation($langcode)` or the entity repository's `getTranslationFromContext()` for the current language.
- Saving the same entity inside its own `hook_entity_presave` or `hook_entity_update` recurses.
- `\Drupal::service()` inside a class is a maintainability issue, not a bug; inject through `create()` or autowiring. In procedural `.module` code it is normal.

## Tests

`tests/src/Unit`, `Kernel`, `Functional` and `FunctionalJavascript` inside the module. They are all evidence for coverage, as the review skill says.

## before_release

`drush updatedb` for a new update hook or post-update function; `drush config:import` for changes in the sync directory; `drush cache:rebuild` for new services, routes, plugins, hooks or templates (`drush deploy` runs all three, plus deploy hooks); enabling a new module (in `core.extension.yml` or with `drush en`); a new `settings.php` value or environment variable; a changed Composer patch.
