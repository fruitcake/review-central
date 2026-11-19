# Reviews, settings and cost

## Settings and instructions

- **Review settings**, in the sidebar, choose the reviewers and their share, the model and the effort for every repository of the tenant. A reviewer can have a monthly budget, in the tenant's currency: once its reviews have cost that much across the tenant it is passed over for the rest of the month. Give the first reviewer a share and a budget and the second a share of 0 to use one until its budget is spent and the other after. A repository can set its own under **Review settings**.
- **Starting a review by comment**, on the same page, says who may start one by commenting `/review` on a pull request: anyone who can comment (the default), only the accounts listed there, or nobody. An account is listed by its GitHub user name or Bitbucket nickname, or its account ID, never by its display name, which anyone can copy. Anyone else gets a reply saying so, and nothing starts. It holds for every repository of the tenant; a review still starts on every push, and from the panel.
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

## Reviews and cost

![The overview of a tenant](/screenshots/overview.png)

![The list of reviews](/screenshots/reviews.png)

A tenant's panel opens on **Overview**: what needs attention (a repository that cannot start, high findings left open, runs that failed), the period's figures, reviews per day, findings by category and the repositories. **Reviews** lists the runs under the views *Needs action*, *Running* and *Failed or timed out*, and a review's own page shows every run on its pull request, the findings and what the run cost. Cost is what the reviewer reported, or an estimate from its tokens and the price list when it reported none.

*Findings accepted* counts what people said of the findings: on a review's own page, where each finding takes *Valid, will fix*, *Not a problem* or *Factually wrong*, and on the rating page. Only a valid finding counts as accepted. It says nothing until findings are answered.

On a repository, **Readiness** checks now whether it can be reviewed: that the connection can read it, that an AI provider is connected, that its runner is turned on, and whether the webhook ever delivered.

The admin panel opens on **Platform**: every tenant with its connections, repositories, reviews and the month's cost against its **monthly budget**, which is set on the tenant and stops nothing. A tenant reads its costs in US dollars or in euros, also set on the tenant, and its budget is in that currency. Its **time zone**, set there too and taken from the platform's settings (`UTC` unless changed), is the one its times are shown in and its days are counted in. A review is costed in dollars; euros are converted on the page at the latest reference rate of the European Central Bank (`review:sync-exchange-rates`), so a past month reads at today's rate. Below it, **Capacity** says whether the installation has room: how long container reviews wait for a worker and how long they take, how often every worker is busy, and the server's processor, memory and disk. Its advice names what to raise: `REVIEW_CONTAINER_PROCESSES` when reviews wait and the server has room, the server itself when it has not. The figures come from a sample the scheduler takes each minute (`review:sample-capacity`), kept for 30 days, so they are empty until the scheduler runs.

## Trying a pull request

**Try without posting** on the list of reviews takes the address of a Bitbucket or GitHub pull request and reviews it without posting anything: no comment, no build status, no task. It is for comparing reviewers, models, efforts and instruction drafts on the same work, and the runs appear under the view *Trials*.

- The pull request may be merged already. It is then reviewed as it went in, against the destination branch as it was before the merge. One that was merged by fast-forward is refused, and one merged by rebase is reviewed on its last commit only: neither leaves a commit that says where the pull request began. A declined one is refused.
- Its repository need not be set up. It is added with *Review its pull requests* turned off, so nothing but a trial runs on it until someone turns that on. The workspace does need a source control connection, except a public GitHub repository.
- *Review as though nobody had* leaves out the comments and earlier findings on the pull request, so two runs start from the same place. Off, the run follows up on what is there.
- It runs on the runner picked in its form, whatever the repository itself uses.

Each published review links to a page where anyone who can read the pull request can rate it.
