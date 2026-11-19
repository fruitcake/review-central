---
paths:
  - '**/*.{php,phtml,xml,graphqls,csv}'
  - '**/requirejs-config.js'
  - 'app/etc/config.php'
---

# Magento 2

Magento's behaviour is decided as much by XML as by PHP. A plugin, observer, preference, cron job, route or API endpoint is a class plus a line of configuration that points at it, and either half can be wrong while the other reads perfectly. Review the XML in the diff as code, and read both halves of every wiring the change adds or moves.

## What the step gives you

`vendor/magento/` holds the core and `vendor/` the extensions when `composer install` could authenticate against repo.magento.com (the step says so in its log when it could not). Read core code there before judging a call into it: what a repository method throws, whether a method is public, which event a class dispatches. There is no `app/etc/env.php`, no database and no `generated/`, so `bin/magento` cannot run anything useful.

Classes named `*Factory`, `*Proxy`, `*Interceptor`, `*ExtensionInterface`, `*ExtensionInterfaceFactory` and `*SearchResults` are generated at compile time. They are not in the repository and not in `vendor/`. A missing generated class is never a finding; a factory whose base class does not exist is.

## Where the wiring lives

Each of these is a reachability check: the configuration has to name something that exists, in the area where it is needed.

- **Areas.** `etc/di.xml` and `etc/events.xml` apply everywhere; `etc/frontend/`, `etc/adminhtml/`, `etc/webapi_rest/`, `etc/graphql/` and `etc/crontab/` only there. A plugin for checkout in `etc/adminhtml/di.xml` never runs on the storefront.
- **Plugins** (`<type><plugin>` in `di.xml`): the method `before`/`after`/`around` + the target method name must match a public method on the target or an interface it implements. Plugins do not apply to `final` classes or methods, `static` or non-public methods, `__construct`, or objects created with `new` rather than through the object manager or a factory. An `around` plugin that does not call `$proceed()` skips the original and every plugin after it; one that calls it and does not return the result returns null. An `after` plugin must return the result. A `before` plugin returns an array of arguments or null.
- **Observers** (`events.xml`): the event name must be dispatched somewhere (grep `dispatch(` in `vendor/magento` and the repository). The observer `name` is unique per event across all modules: a second module using the same name for the same event replaces the first observer.
- **Preferences** replace a class for the whole application. Two modules preferring the same class means only one wins, decided by module load order. Prefer a plugin; a new preference on a core class is worth a sentence on why.
- **Cron** (`crontab.xml`): `instance` and `method` exist, the group exists, the schedule or `config_path` is valid.
- **Routes**: `routes.xml` front name and id, the controller at `Controller/<Path>/<Action>.php`, and the layout handle `<routeId>_<controller>_<action>.xml`. A layout file named after the front name instead of the route id never loads.
- **Web API** (`webapi.xml`): the service class and method exist on the interface, and the `<resource>` is right (see Security).
- **Module registration**: `registration.php`, `etc/module.xml`, and `<sequence>` on every module whose configuration this one extends or overrides; without it, load order decides whose config wins. When the repository commits `app/etc/config.php`, a new module belongs in it.
- **Extension attributes**: `extension_attributes.xml` declares them, and something has to fill them (usually a plugin on the repository's `get`/`getList`); a getter nothing populates always returns null.
- **Layout XML**: a `<referenceBlock>` or `<referenceContainer>` to a name that does not exist in the parent layout is ignored silently, as is a `<move>` to a missing destination.

## Security

- **Admin controllers** extend `Magento\Backend\App\Action` and set `const ADMIN_RESOURCE` to an ACL id that exists in `acl.xml`. The inherited default `Magento_Backend::admin` lets every admin user in, whatever their role. A sibling controller in the same folder with a narrower resource shows what this one lacks.
- **Storefront controllers** implement `HttpGetActionInterface` and/or `HttpPostActionInterface`. A state-changing action reachable over GET has no form key check; one implementing `CsrfAwareActionInterface` whose `validateForCsrf()` returns true has switched CSRF off, which is only right for a webhook that verifies its own signature.
- **A record fetched by an id from the request**: `OrderRepositoryInterface::get()`, `AddressRepositoryInterface::getById()`, `CartRepositoryInterface::get()` and the like do no ownership check. The controller has to compare `customer_id` with the session's customer.
- **Web API resources**: `<resource ref="anonymous"/>` makes the endpoint public. A `self` endpoint must take the customer id from `<parameter name="customerId" force="true">%customer_id%</parameter>`, not from the request body.
- **GraphQL resolvers** check `$context->getExtensionAttributes()->getIsCustomer()` and scope by `$context->getUserId()` before returning customer data.
- **Escaping in `.phtml`**: `$escaper->escapeHtml()`, `escapeHtmlAttr()`, `escapeUrl()`, `escapeJs()`, each for its own context. A raw `<?= $block->getX() ?>` of anything a customer or admin entered is XSS. `/* @noEscape */` says the value is safe; check that it is. `escapeHtml` inside an attribute, or `json_encode` into an attribute without `escapeHtmlAttr`, is the wrong sink.
- **SQL**: `$connection->query()` or `->where()` with an interpolated variable. `where('col = ?', $value)`, `quoteInto` and `addFieldToFilter` bind safely.
- **Full page cache**: a block on a cacheable page that renders customer-specific data (name, cart, prices for a customer group, an address) is cached with the first visitor's data and served to the next. Private data belongs in customer-data sections (`sections.xml`) loaded by JavaScript. This is a security finding when it shows one customer's data to another.
- `unserialize()` on anything that is not a constant; use `Magento\Framework\Serialize\Serializer\Json`.
- Secrets in `system.xml` without `backend_model="Magento\Config\Model\Config\Backend\Encrypted"`, or not marked sensitive in `di.xml`, end up in plain text in `core_config_data` and in `config.php` on `app:config:dump`.

## Performance

- `$model->load($id)`, `$repository->getById()` or `getList()` per item inside a loop; one collection with `addFieldToFilter('entity_id', ['in' => $ids])` replaces it.
- A collection or `getList()` with no page size on a catalogue, customer or sales table.
- `addAttributeToSelect('*')` on a product collection.
- `cacheable="false"` on any block in a layout disables the full page cache for every page using that handle. On `default.xml`, catalogue, CMS or category handles that is the whole shop: HIGH.
- Observers on hot events (`controller_action_predispatch`, `catalog_product_load_after`, `sales_quote_collect_totals_*`, `layout_load_before`) and plugins on hot methods that query, call HTTP or save.
- A console command that injects heavy dependencies without a `Proxy` in `di.xml`: every command is constructed on every `bin/magento` call, including during `setup:install`, when there is no database yet.
- A block with `cache_lifetime` and no `getIdentities()`/cache tags serves stale content after the entity changes.

## Correctness

- **Scope.** `ScopeConfigInterface::getValue($path)` without `ScopeInterface::SCOPE_STORE` (or website) reads the default scope, which is wrong in a multi-store shop. In cron, CLI and admin, the "current store" is the admin store (id 0); code that needs a storefront store has to be given one.
- **Area.** CLI commands and cron sending email or rendering templates need `Magento\Store\Model\App\Emulation`. `State::setAreaCode()` throws when the area is already set.
- **Money.** `base_*` against store-currency fields (`base_grand_total` against `grand_total`), and rounding through `PriceCurrencyInterface`.
- **Patches run once.** A data or schema patch is recorded in `patch_list`. Editing a patch that already ran does nothing where it ran; renaming or moving its class runs it again. A patch has to be safe to run on a database where its data already exists.
- **Declarative schema.** A column or table removed or renamed in `db_schema.xml` is only dropped when `db_schema_whitelist.json` lists it.
- **Translations.** `__('... %1', $x)` with numbered placeholders, and the string present in `i18n/*.csv` where the repository keeps them.
- **Interface over implementation.** Type hints and DI arguments on `Api\*Interface`, not the concrete model, or a preference elsewhere breaks the call.

## Tests

Unit tests live in `Test/Unit/` inside the module, integration tests in `Test/Integration/` or `dev/tests/integration/`. Both are evidence for coverage, as the review skill says.

## before_release

`bin/magento setup:upgrade` for a new module, a `db_schema.xml` change, a new patch or a `module.xml` change; `setup:di:compile` for `di.xml` or constructor changes in production mode; `setup:static-content:deploy` for templates, LESS or JavaScript; `app:config:import` for a changed `config.php`; a new value in `env.php` or `config:set`; `indexer:reindex` for a new indexer or mview; restarting queue consumers for changed `communication.xml`/`queue_*.xml`; `cache:flush`. Name only the ones the diff needs; the deploy usually runs the first three already.
