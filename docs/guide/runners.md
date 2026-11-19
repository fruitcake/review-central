# Where a review runs

How a review runs is its **runner**. There are three, from the lightest to the strongest:

| Runner | What the reviewer sees | What it needs |
|---|---|---|
| `diff` | The pull request's diff, with its ticket, comments and earlier findings. Nothing is cloned and nothing runs. | Only an AI provider key. The default. |
| `docker` | The whole checkout, in a throwaway container under gVisor on your own server. | Docker, gVisor and a worker of its own. |
| `cloudflare` | The whole checkout, in a Cloudflare sandbox. **Experimental.** | A Cloudflare account and the Worker deployed. |

The platform's runner is picked under **Admin → Settings**, from the ones that are set up, and a repository can pick its own under **Runs on**. **Admin → Setup** says which runners are set up and what the others still need.

![The setup page](/screenshots/setup.png)

## A container on this server

The review runs as a queued job on this installation's own worker. What it prints is kept in a file while it runs, and the review's page in the tenant panel shows it as it goes, asking again every few seconds. The log may quote the repository's code; it is removed after `REVIEW_CONTAINER_LOG_DAYS` (14), and `REVIEW_CONTAINER_LOG=false` keeps none. The connection's webhook starts a review for every new commit on a pull request that is not a draft, and the pull request shows it among its checks as "AI Review": a build status on Bitbucket, a commit status on GitHub.

The job clones the repository on the worker, and then runs the review's scripts in containers that are thrown away:

- the reviewer is the only step that sees the pull request's code. It gets the AI provider's key and nothing else: no credential for this server or for the repository's host. It cannot change the scripts the other steps run.
- the worker itself decides whether to review and writes what the reviewer reads: the pull request, its comments, the ticket and the instructions. That reads no code, so it needs no container.
- the worker reads the report the reviewer wrote, as data, and files it: the review on the pull request and, on Bitbucket, the Code Insights report on the commit. Each review uses one container; a review that is turned away uses none.

To turn it on, on a machine that runs `php artisan queue:work --queue=reviews`:

1. Install Docker and a sandboxing runtime. The default is gVisor (`runsc`); set `REVIEW_CONTAINER_RUNTIME` for another, such as Kata. Docker's own runtime shares the host's kernel with the code under review: use it for your own repositories at most.
2. Build the image: `docker build -t review-central/reviewer:latest docker/reviewer`.
3. Set `REVIEW_DOCKER_ENABLED=true`. Until then a repository on Docker says in its readiness that the runner is not set up, and no review starts.
4. Set the queue connection's `retry_after` above the job's timeout, or a long review is started a second time. The job may take the reviewer's limit plus ten minutes (`REVIEW_CONTAINER_TIMEOUT` + 600 seconds) for each review it does, and up to three when it [carries on with a new commit](#carrying-on-with-a-new-commit). With Redis the `reviews` connection is sized for that already (7,500 seconds); on the database queue set `DB_QUEUE_RETRY_AFTER=7500`.

A review's page shows the most memory its container was seen to use, sampled every `REVIEW_CONTAINER_SAMPLE_SECONDS` (10), next to the limit `REVIEW_CONTAINER_MEMORY` gives it. The limit is a ceiling, not a reservation; where reviews stay well under it, lowering it lets more of them run side by side.

The worker can start containers, which is as good as root on that machine: give it a machine of its own that reaches only the database, the cache and Docker. `config/review.php` has the limits, the networks of the steps and the address the containers reach this server at.

The reviewer step installs `vendor/` as production has it (`composer install --no-dev`), so the reviewer can read the framework's and the packages' own code. Composer runs no scripts and no plugins there, and no server is started from the checkout: the pull request names both in its own files, and the step holds the AI provider's key. The installed `vendor/` is kept in the repository's cache directory under the hash of `composer.json` and `composer.lock`, and the next review with the same lock unpacks it instead of installing; the three newest are kept.

The clone has the full history without the file contents (`--filter=blob:none`); the worker fetches the contents of what the review compares before the reviewer starts. Nothing of a repository is kept after its review, except what Composer downloaded for it: that stays in `REVIEW_CONTAINER_CACHE_DIRECTORY`, one directory per repository, so the next review of it installs from disk. It is removed when the repository had no review for `REVIEW_CONTAINER_CACHE_DAYS` (14). Set the directory empty to keep nothing.

## The diff alone

The lightest review, and the default until another runner is set up and picked. The job reads the pull request's diff from the repository's host and sends it to the tenant's AI provider in one request, with the pull request, its ticket, its comments and the findings of earlier reviews. What comes back is posted as any review is: the summary, a comment per finding, tasks, and an approval or a request for changes.

Nothing is cloned and no container starts, so it needs no Docker and finishes in a minute or two. The reviewer sees only what the diff shows: it cannot read the code around a change, a caller or a definition, so it finds less and is told to report only what the diff itself establishes. Lock files, built files and binary files are left out, and so are whole files once the diff passes `REVIEW_DIFF_MAX_BYTES` (600,000); the review's log names each.

Which provider is asked follows the repository's reviewer settings: Codex means OpenAI, anything else Anthropic, and a tenant with one provider is asked through that one. The model and effort are the ones set for that reviewer. A Claude model that is an alias only Claude Code knows, such as `opus`, gives way to `claude-opus-5-5`; without a Codex model, OpenAI is asked for `REVIEW_DIFF_OPENAI_MODEL`. On Anthropic's own API a request a safety classifier declines is run again on the model Anthropic recommends for it (`fallbacks`), which a diff full of security code can need.

`REVIEW_DIFF_ENABLED=false` takes the choice away.

## A Cloudflare sandbox

::: warning Experimental
This runner has not yet run against Cloudflare itself: the Worker type-checks against Cloudflare's own types and the PHP side is tested against a faked Worker. Try it locally first, and use Docker or the diff for reviews you rely on. Reports of how it went are welcome as [GitHub issues](https://github.com/fruitcake/review-central/issues).
:::

The reviewer can also run in a [Cloudflare sandbox](https://developers.cloudflare.com/sandbox/): the job stays on this server, and the pull request's code runs on Cloudflare's side, in a container of its own for every review. It needs no Docker on the server and scales with the number of reviews. Deploying the Worker and its bucket, and running it locally with `npx wrangler dev` to try it, is described in [`cloudflare/README.md`](https://github.com/fruitcake/review-central/blob/main/cloudflare/README.md). **Admin → Setup** shows whether the Worker answers and is up to date.

## The reviewer's session, and asking it a question

The reviewer's home is kept on the worker per repository and branch (`REVIEW_CONTAINER_SESSION_DIRECTORY`), with the session Claude Code ended its review in. The next review of that branch, from a fresh clone, resumes the session and starts from what the last one read and found. After `REVIEW_CONTAINER_SESSION_RESUMES` (3) reviews in a row a review starts fresh: a session that followed that many commits carries what was true of code that is gone. The home is removed when the pull request is merged or declined, and after `REVIEW_CONTAINER_SESSION_DAYS` (14) without a review. A trial keeps none. The Codex and OpenCodeReview reviewers do not resume a session.

A comment on a pull request that starts with `@review` is a question for the reviewer:

> @review did you mean the export or the import in F2?

The reviewer answers in a reply under that comment. A job clones the branch and runs Claude Code once in the sandbox a review runs in, in a copy of the branch's session, so it answers from its last review where there is one and from the code otherwise. The answer is no part of the review: it changes no finding, and the copy is thrown away. An answer is capped at 2 dollars, a pull request gets ten an hour, and the review's page lists the questions with what each cost. It needs the container runner and an Anthropic connection; without either the reply says so.

## Carrying on with a new commit

A push that arrives while a pull request is being reviewed normally waits, and then starts a review of its own from a fresh clone. With **New commit during a review** set to carry on, under the tenant's default review settings or a repository's own, the job that is reviewing carries on instead: its review is published first, then it fetches the branch into the checkout it already has and reviews again, once or up to twice in a row. Claude Code resumes the session of the review before, so it starts from what it already read and found; the other reviewers start fresh in the same checkout.

A review is listed from the moment it is queued, not only once it starts running. The review's list has it as **Queued**, and its commit on the pull request shows "AI Review" in progress, with "Waiting for a worker" or, for a commit the reviewing job will carry on with, "Waiting for the review of the commit before it". When the job gets to it, the same record becomes the running review. A queued review that is told there is nothing to review, or that a newer commit overtakes, is closed as skipped and its commit says so.

While this is on, the reviewer cannot write the clone's `.git` directory, because git runs on the worker again afterwards with the host's credentials, and it keeps a home directory between its reviews that no other step sees. A job gets the time of every review it may do, at most two after its own: up to two hours with the default limit. Horizon's timeout and the `reviews` connection's `retry_after` are sized for that; Horizon's stop timeout, set where it runs (Forge, systemd or Supervisor), has to be too: see [Installation](./installation).

## Horizon

With Redis as the queue, Horizon runs the workers, and `/horizon` shows them to an administrator. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and `REVIEW_CONTAINER_CONNECTION=reviews`; that connection's `retry_after` already fits a review.

`php artisan horizon` runs everything on one machine: the application's own jobs, and one process per review at a time, `REVIEW_CONTAINER_PROCESSES` of them (2 by default). Forge keeps it running; elsewhere use systemd or Supervisor. Run `php artisan horizon:terminate` after a deploy so it restarts on the new code. A review that is running is finished first.

To move the reviews to a machine of their own later, start Horizon there with `--environment=worker` and on the application's server with `--environment=application`. Both then use the same Redis and the same cache: the review lock lives in the cache.

## Another system

A runner of your own is a class: see [Extending](./extending#adding-a-runner).
