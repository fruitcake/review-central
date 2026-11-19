---
paths:
  - '**/*.php'
---

# Laravel

The stack's own rules come from the stack's own skills where the repository has them, and this guide does not restate them. Laravel Boost is a dev dependency, and the review installs none, so they are only there when the repository commits them (`.claude/skills/` or `.agents/skills/`). Then they are the authority on what good looks like here: load `laravel-best-practices`, then open the rule files under `laravel-best-practices/rules/` that cover what the diff touches: `security.md` for authorisation, mass assignment, escaping, uploads and rate limiting; `db-performance.md` for query counts, eager loading and indexes; then `eloquent.md`, `queue-jobs.md`, `error-handling.md`, `scheduling.md`, `validation.md` or `migrations.md` as the change requires. Load `livewire-development` when the diff touches Livewire.

## What the step gives you

`vendor/` is installed as production has it, without the development packages: read a package's own code there before judging its API. There is no database and no `.env`.

## Where the wiring lives

A class nothing reaches reads as correct code. Check the other side of each of these:

- a listener or observer: `EventServiceProvider`, `#[ObservedBy]`, or event discovery in `bootstrap/app.php`;
- a scheduled command: `routes/console.php` or the console kernel;
- a route: `routes/*.php`, and the middleware group it lands in;
- a policy: the model-to-policy mapping or naming convention, and the `authorize`/`can` call that uses it;
- a queued job: the connection and queue it is dispatched to, and whether a worker listens there.

## before_release

A new migration (`php artisan migrate`), a new `config()` or `env()` key, a changed queue connection, a new scheduled command, a new queue that needs a worker.
