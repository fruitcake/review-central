---
paths:
  - '**/*.{vue,js,mjs,cjs,ts,mts,json}'
---

# Nuxt

A Nuxt 3 or 4 app on Vue 3: pages, components, composables and stores under `app/` (or the repository root on Nuxt 3), a Nitro server under `server/`, and `nuxt.config.ts` that wires the modules and the runtime config. With SSR on, the same component code runs on the server and in the browser.

The stack's own rules come from the stack's own skills where the repository has them, and this guide does not restate them. The review installs none, so they are only there when the repository commits them (`.claude/skills/` or `.agents/skills/`; in a Claude review the repository's `.claude/skills/` is renamed to `.claude/skills.as-text/`, so it is read and not loaded). Then they are the authority on what good looks like here: read the `SKILL.md` of each one that covers what the diff touches, such as `vue-best-practices`, `vue`, `nuxt-ui`, `vueuse-functions` or `vitest`, and the files it points to for the case in front of you. Rules the repository keeps for its agents (`AGENTS.md`, `CLAUDE.md`, `.claude/rules/`, `.agents/rules/`, `.cursor/rules/`) say how this app does things; a change that goes against one is a finding of the kind the rule describes.

## What the step gives you

The checkout, without `node_modules/` or Yarn's cache: the review runs no `npm`, `yarn` or `pnpm`, since those run install scripts from the pull request and its dependencies. There is no `.nuxt/` either, so the generated types and auto-import declarations are not there. Judge a library's use by `package.json`, the lockfile and how the repository already calls it, and leave an API question you cannot settle from that as an open question rather than a finding. There is no `.env` and no running app.

## Where the wiring lives

A page, component or handler nothing reaches reads as correct code. Check the other side of each of these:

- **Pages** are routes by their path under `pages/`: a renamed or moved file changes the URL, and every `navigateTo`, `<NuxtLink>` and `router.push` to the old one breaks. `definePageMeta` sets the layout and the middleware the page runs.
- **Route middleware** in `middleware/` runs only where a page names it in `definePageMeta`, unless the file ends in `.global`. An auth or permission check in a middleware no page names protects nothing.
- **Components and composables.** Nuxt auto-imports from `components/` and `composables/` unless `nuxt.config.ts` turns that off (`components: { dirs: [] }`, `imports: { autoImport: false }`); then each one needs its import. Check which the repository does before calling a missing import a defect, or an import a redundant one.
- **Plugins** in `plugins/` run in name order, with `.client` or `.server` in the name limiting them to one side.
- **Server routes** under `server/api/` and `server/routes/` are endpoints by their path and method suffix (`users.get.ts`); `server/middleware/` runs on every request.
- **Runtime config** keys in `nuxt.config.ts` are filled from `NUXT_*` (or `NUXT_PUBLIC_*`) variables at runtime. A key read with `useRuntimeConfig()` but not declared there is undefined.

## Security

What the browser gets is readable by anyone. The server boundary is `server/` and whatever API the app calls.

- **`runtimeConfig.public`** is sent to the browser with every page. A secret there (an API secret, a private key, a token with more reach than one user's own access) is a finding, whatever it is called. Browser keys meant to be public, such as a Maps key restricted by referrer, are not.
- **`v-html`** and `innerHTML` with anything that is not the app's own fixed markup is XSS: user input, API data, translations with placeholders, and markdown rendered without sanitising.
- **Client-side checks are not authorisation.** Middleware, `v-if` on a role, and a hidden button keep a page tidy; only the API decides what a user may do. A change that relies on the client for it is a finding when the API side does not check.
- **Server routes** read input from the query, body and headers as untrusted, and check who is asking before acting.
- **Tokens** in `localStorage`, or in a cookie the client can read, are open to any script on the page; an `httpOnly` cookie is not.
- **SSR state.** With SSR, a store, `useState` or module-level variable defined outside a request can be shared between users on the server. Per-user data there leaks to the next request.

## Correctness

- **SSR and the browser.** `window`, `document`, `localStorage` and browser-only libraries are not there on the server: used in `setup` or at module level without `import.meta.client`, `onMounted` or `<ClientOnly>`, they break server rendering. Markup that differs between server and browser (dates, random ids, the viewport) gives hydration mismatches.
- **Data fetching.** `useFetch` and `useAsyncData` run once on the server and are reused in the browser; a key that is not unique per request shares data between calls, and one that does not change with its inputs does not refetch. `$fetch` in `setup` runs twice. A request without an error path leaves a spinner or an empty page.
- **Reactivity.** Destructuring a `reactive` object or a store without `storeToRefs` loses reactivity; a `watch` that should run at once needs `immediate`; a listener, interval or observer started in a component is stopped in `onUnmounted`.
- **i18n.** A new user-facing string goes into every locale file the app keeps, under the same key.
- **Dates and times** render in the server's time zone during SSR and the user's in the browser.

## Tests

Vitest with `@vue/test-utils`, as the repository configures it. A composable or store used in more than one place, and logic with branches, is where a missing test counts most.

## before_release

- **A new environment variable** (`NUXT_*`, or a key added to `runtimeConfig`) has to be set wherever the app runs before it is deployed.
- **A changed route** (a moved or renamed page) needs redirects for links people and search engines have.
- **A new or changed server endpoint or API contract** the app calls has to be deployed on the API side first.
