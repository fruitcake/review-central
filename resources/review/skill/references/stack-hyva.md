---
paths:
  - '**/*.{phtml,js,css,xml}'
  - '**/tailwind.config.js'
  - '**/hyva-themes.json'
  - '**/hyva.config.json'
  - 'app/design/frontend/**'
---

# Hyvä

Hyvä is a Magento storefront theme, so the Magento guide applies as well. It replaces Luma's RequireJS, KnockoutJS and jQuery with Alpine.js and Tailwind CSS. A theme is a child of `Hyva/default` (or `Hyva/default-csp`), declared in `app/design/frontend/<Vendor>/<theme>/theme.xml`, with its Tailwind build in `web/tailwind/`.

## Hyvä's own skill

`.review-skills/hyva-alpine-component/SKILL.md` is Hyvä's reference for Alpine components, fetched by the review step at a pinned version (or `.claude/skills/hyva-alpine-component/` when the repository installed it, which a Claude review finds under `.review-repo-skills/.claude/skills/`). Read it before judging a template with `x-data`: it has the CSP patterns, the `hyva` helpers and the escaping rules. It recommends CSP patterns everywhere; on a theme that is not CSP, a standard Alpine expression still works and is not a finding.

## Luma code on a Hyvä page does nothing

There is no RequireJS on a Hyvä page. `data-mage-init`, `<script type="text/x-magento-init">`, `require([...])`, `define([...])`, Knockout `data-bind` and jQuery calls in a template rendered by a Hyvä theme do nothing, and nothing reports it: the feature silently fails. That is a reachability finding unless the page is rendered by a Luma fallback (`Hyva_LumaCheckout`, a theme fallback route) or a Hyvä compatibility module replaces the template. A module's Luma template added to a Hyvä shop with no Hyvä version of it is the same finding.

## Tailwind

- Tailwind only generates classes it finds as complete strings in the files its `content` globs cover. A class built at runtime (`'text-' + color`, `"bg-{$variant}-500"`) is missing from the production CSS. Use the full class names in a lookup.
- A template outside what Tailwind scans gets no styles either. Which file decides that depends on the version, in `web/tailwind/package.json`: with Tailwind v4 (Hyvä 1.4+) it is `tailwind.include` and `tailwind.exclude` in `web/tailwind/hyva.config.json`, plus the modules registered in `app/etc/hyva-themes.json` (written by `bin/magento hyva:config:generate`); with Tailwind v3 it is `content` in `tailwind.config.js` and `excludeDirs` in `postcss.config.js`. Editing the file the theme's version does not read changes nothing.
- When the repository commits the built `web/css/styles.css`, a template using new classes with no rebuilt CSS in the diff ships unstyled.

## Alpine and CSP

- A component function (`x-data="initFoo()"`) has to be defined in a script before Alpine starts; Hyvä templates define it in an inline `<script>` in the same template.
- On a CSP theme (`Hyva/default-csp`, or a shop with a strict CSP), inline expressions in Alpine attributes (`x-on:click="open = !open"`, `x-data="{ open: false }"`) are not evaluated. Components are registered with `Alpine.data()` inside an `alpine:init` listener, and each inline `<script>` is followed by `$hyvaCsp->registerInlineScript()`. `x-model` and range loops (`x-for="i in 10"`) do not exist in the CSP build. Code that works on the default theme breaks there.
- `x-html` with anything a customer or admin entered is XSS.
- Data handed to Alpine through an attribute is escaped for that attribute: `$escaper->escapeHtmlAttr(json_encode($data))`. A bare `json_encode` in an attribute lets a `"` break out. Into a `<script>`, use `json_encode` with `JSON_HEX_TAG`, or `$escaper->escapeJs()` for a string.

## Requests from the page

- A `fetch` POST to a Magento controller needs the form key (`hyva.getFormKey()`, or `form_key` from the `formkey` block); without it Magento rejects the request and the feature appears to do nothing.
- Customer data comes from the `private-content-loaded` event (customer sections), not from server-rendered blocks on a cached page. See the full page cache rule in the Magento guide.

## Magewire (Hyvä Checkout)

Magewire is Livewire for Magento. A component's public properties are sent to the browser and can be changed by it before the next request. A public property holding a price, a quote or address id, or anything that decides access, has to be validated or re-read server-side in the method that uses it. Methods marked public are callable from the browser.

## ViewModels

Hyvä templates get helpers through `$viewModels->require(SomeViewModel::class)`. A view model is a plain class implementing `ArgumentInterface`; it does not need layout XML.

## before_release

A Tailwind build (`npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind run build-prod`) when the deploy does not build it; `bin/magento hyva:config:generate` when a module with Tailwind templates is added; static content deploy.
