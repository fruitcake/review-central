# Reviews, settings and cost

## Settings and instructions

- **Review defaults**, in the sidebar, choose the reviewers and their share, the model and the effort for every repository of the tenant (a model is picked from a list: Claude Code's aliases, the provider's *smartest*, *default* or *cheapest*, which follow what the Laravel AI SDK names when the review runs, the models the price list knows, or any name typed), and also the review's language, whether it grades the change against its ticket, Claude Code's extra passes and its advisor, a second check of each finding, and the most a review may cost (**Budget per review**, $10 unless changed). A reviewer can have a monthly budget, in the tenant's currency: once its reviews have cost that much across the tenant it is passed over for the rest of the month. Give the first reviewer a share and a budget and the second a share of 0 to use one until its budget is spent and the other after. A repository can set its own under **Review settings**.
- **Trusted accounts**, on the same page, are the tenant's own people on its hosts: listed by GitHub user name or Bitbucket nickname, or account ID, never by display name, which anyone can copy. A repository can review only their pull requests by itself (see [Pull requests](#pull-requests)), and the `/review` command can be limited to them.
- **Starting a review by comment**, on the same page, says who may start one by commenting `/review` on a pull request: anyone who can comment (the default), only the trusted accounts, or nobody. Anyone else gets a reply saying so, and nothing starts. It holds for every repository of the tenant; on one that reviews only the trusted accounts' pull requests, only they can, whatever this says. A review still starts on every push, from **Review a pull request** under Reviews, which posts on the pull request as a push would, and with `php artisan review:start <repository> <branch>`.
- **Pull requests from forks**, on the same page, are not reviewed by themselves unless that is turned on: a fork's code is anyone's, and a review runs it in the sandbox with the tenant's AI key and on its budget. Turned on, a pull request from a fork on GitHub is reviewed on every push and on a comment, as any other; off, it waits under **Pull requests**, and a `/review` comment or a question on one gets a reply saying so. On Bitbucket, which keeps no copy of a fork's branch in the repository, a pull request from a fork is never reviewed by itself; it can be [tried](#trying-a-pull-request) from the diff alone.
- **Instructions** are what the review is told to look for: a method, the defect lenses and a guide per stack. The administrator keeps the defaults; a tenant can copy one and change it for its own repositories. What is saved is what the next review reads.

## What the reviewer reads in the repository

A team writes down how its code works, and what counts as a defect there, in the repository itself. The reviewer reads these files where they exist, and they win over its general rules:

| File | What it is for |
|---|---|
| `CLAUDE.md` or `AGENTS.md` at the root | The codebase's conventions, for any coding agent. One in a subdirectory holds the rules for the files under it, and the nearer one wins. |
| `.ai/rules/` | Rule files per area, with an `index.md` that maps file globs to them. For OpenCodeReview, a rule file can also name its globs in a `paths:` front matter, and goes with the review of each file it matches. |
| `REVIEW.md` at the root | Instructions for review only: what to report, at what severity, which paths to leave alone, checks to make on every pull request. Read from the destination branch, so a pull request cannot change the rules it is reviewed against. |
| `.ai/review-conventions.md` | Patterns judged false positives before, one per line, which the reviewer does not report again. A review lists candidates for it under **Suggested convention entries** at the end of its summary, to paste in when a person agrees. |
| `.opencodereview/rule.json` | OpenCodeReview's own rules, read from the destination branch. |

None of them is needed: without them the reviewer goes by the review instructions and the stack guides alone. They can raise what the reviewer reports, never lower what it needs as evidence or change what it may run.

## Pull requests

**Pull requests** lists the open pull requests of the tenant's repositories, as their webhooks told of them: its title, its author, and where its review stands. It fills as webhooks arrive, so a pull request opened before this shows once it changes.

A repository reviews every pull request by itself on a push, unless its settings say otherwise:

- **Review its pull requests** off: nothing starts by itself. Its pull requests are listed, and a tenant member starts a review with **Review**.
- **Whose pull requests are reviewed by themselves** set to the trusted accounts: theirs are reviewed on every push, as before. Anyone else's waits, and so does a pull request from a fork while those are not reviewed by themselves. A waiting pull request shows on its host as an *AI Review* build status in progress, *Waiting for someone in the team to start the review*, which leads here, under the *Waiting for approval* tab. **Approve and review** starts it; the review's own status then replaces the waiting one. A new push waits again.

For a public repository this keeps outside contributors from spending the tenant's AI budget, while the team's own pull requests are reviewed as they are pushed.

### Whose turn

Each pull request says whose turn it is, and what they have to do, read from its reviewers, its newest commits and its AI review:

| The pull request | Whose turn |
| --- | --- |
| is a draft | the author, to mark it ready |
| waits for approval | someone in the team, to start the review |
| is being reviewed by the AI | the AI review |
| has a reviewer's request for changes, with new commits since | that reviewer, to review again |
| has a reviewer's request for changes | the author |
| failed its AI review on its newest commit | the author |
| has no reviewer | nobody yet: assign one |
| has an approval from before its newest commits | that reviewer, to review again |
| has reviewers who have not reviewed | them |
| is approved by every reviewer | the author, to merge |

Where the tenant has an AI provider, a small model also writes it in a sentence or two from the description, the comments, the history and the AI review, and sums up what the pull request changes: Anthropic's Haiku, OpenAI's `REVIEW_TURN_OPENAI_MODEL`, or the model another provider's connection names. It is written once each time the state changes; until then, and with `REVIEW_TURN_NOTES=false`, the rules' own words show. The account the review acts as is not counted as a reviewer.

**With me** lists the pull requests whose turn is yours. It goes by the accounts on your profile (the menu at the top right): your Bitbucket nickname or account ID and your GitHub login. With none listed, your name is matched to the name the host shows. A row's details show the reviewers, the summary and the history: opened, pushed, asked to review, approved, changes requested, and each AI review.

Approvals and requests for changes reach Review Central through the webhook events listed under [Setting up a tenant](./tenants). `php artisan review:sync-pull-requests` reads every listed pull request from its host again, for those from before and for a delivery that was missed; it runs every night by itself.

## A security review of a branch

**Security review**, under Reviews, has the reviewer read a whole branch for security problems as it stands, rather than one pull request's change: pick the repository and the branch, such as the default one. Nothing is posted; the report shows under the *Security* view. It reads far more than a pull request's review, so it takes longer and costs more. It needs a runner that reads the code, a Cloudflare sandbox or Docker, and an Anthropic connection, since Claude Code is its reviewer. Its model and effort are set with `SECURITY_REVIEW_MODEL` and the variables beside it in the [configuration](./configuration).

## Reviews and cost

![The overview of a tenant](/screenshots/overview.png)

![The list of reviews](/screenshots/reviews.png)

A tenant's panel opens on **Overview**: what needs attention (a repository that cannot start, high findings left open, runs that failed), the period's figures, reviews per day, findings by category and the repositories. **Reviews** lists the runs under the views *Needs action*, *Queued or running*, *Did not finish*, *Skipped or waiting*, *Trials* and *Security*, **Questions** lists what was asked with `@review` and what each answer cost, and a review's own page shows every run on its pull request, the findings and what the run cost. Cost is what the reviewer reported, or an estimate from its tokens and the price list when it reported none.

*Findings accepted* counts what people said of the findings: on a review's own page, where each finding takes *Valid, will fix*, *Not a problem* or *Factually wrong*, and on the rating page. Only a valid finding counts as accepted. It says nothing until findings are answered.

On a repository, **Readiness** checks now whether it can be reviewed: that the connection can read it, that an AI provider is connected, that its runner is turned on, and whether the webhook ever delivered.

The admin panel opens on **Platform**: every tenant with its connections, repositories, reviews and the month's cost against its **monthly budget**, which is set on the tenant and stops nothing. A tenant reads its costs in US dollars or in euros, also set on the tenant, and its budget is in that currency. Its **time zone**, set there too and taken from the platform's settings (`UTC` unless changed), is the one its times are shown in and its days are counted in. A review is costed in dollars; euros are converted on the page at the latest reference rate of the European Central Bank (`review:sync-exchange-rates`), so a past month reads at today's rate. Below it, **Capacity** says whether the installation has room: how long container reviews wait for a worker and how long they take, how often every worker is busy, and the server's processor, memory and disk. Its advice names what to raise: `REVIEW_CONTAINER_PROCESSES` when reviews wait and the server has room, the server itself when it has not. Processor and memory advice for Docker counts only while a repository still reviews on Docker, and only the load while reviews ran there. For Cloudflare sandboxes it advises a size, with a button that switches to it: memory from every review, processor per size from the reviews on that size, never a size that was short of processor before, and after a change it waits for five reviews on the new size. A table per sandbox size shows what each step of the review took on average and for nine in ten, its most memory and how long it waited for a processor, with what a review took and cost in the sandbox. The figures come from a sample the scheduler takes each minute (`review:sample-capacity`), kept for 30 days, so they are empty until the scheduler runs.

## Trying a pull request

**Try without posting** on the list of reviews takes the address of a Bitbucket or GitHub pull request and reviews it without posting anything: no comment, no build status, no task. It is for comparing reviewers, models, efforts and instruction drafts on the same work, and the runs appear under the view *Trials*.

- The pull request may be merged already. It is then reviewed as it went in, against the destination branch as it was before the merge. One that was merged by fast-forward is refused, and one merged by rebase is reviewed on its last commit only: neither leaves a commit that says where the pull request began. A declined one is refused.
- Its repository need not be set up. It is added with *Review its pull requests* turned off, so nothing but a trial runs on it until someone turns that on. The workspace does need a source control connection, except a public GitHub repository.
- *Review as though nobody had* leaves out the comments and earlier findings on the pull request, so two runs start from the same place. Off, the run follows up on what is there.
- It runs on the runner picked in its form, whatever the repository itself uses.

Each published review links to a page where anyone who can read the pull request can rate it.
