# Reviews, settings and cost

## Settings and instructions

- **Review defaults**, in the sidebar, choose the reviewers in their order with their share, and each reviewer's model and effort, for every repository of the tenant. At its top it says what runs as the form stands: the reviewer, or the reviewers by share, with the model and effort each runs at, and which have no key; a model or effort left empty is **Not set**: the reviewer picks its own, the same way for every pull request, known once a review ran, so it says what the last one used, except for an effort left empty everywhere, which is the platform's default (High for Claude Code, Medium for the others) and says so; *smartest*, *default* and *cheapest* are the Laravel AI SDK's tiers, picked by name (a model is picked from a list: Claude Code's aliases, the provider's *smartest*, *default* or *cheapest*, which follow what the Laravel AI SDK names when the review runs, the models the price list knows, or any name typed), and also the review's language, whether it grades the change against its ticket, Claude Code's extra passes and its advisor, a second check of each finding, and the most a review may cost (**Budget per review**, $10 unless changed). A reviewer can have a monthly budget, in the tenant's currency: once its reviews have cost that much across the tenant it is passed over for the rest of the month. A reviewer with a share of 0 is a fallback: it runs only when none with a share can, the first of the fallbacks in list order. Give the first reviewer a share and a budget and the second a share of 0 to use one until its budget is spent and the other after. A repository can set its own under **Review settings**.
- **Trusted accounts**, on the same page, are the tenant's own people on its hosts: listed by GitHub user name or Bitbucket nickname, or account ID, never by display name, which anyone can copy. A repository can review only their pull requests by itself (see [Pull requests](#pull-requests)), and the `/review` command can be limited to them.
- **Starting a review by comment**, on the same page, says who may start one by commenting `/review` on a pull request: anyone who can comment (the default), only the trusted accounts, or nobody. Anyone else gets a reply saying so, and nothing starts. It holds for every repository of the tenant; on one that reviews only the trusted accounts' pull requests, only they can, whatever this says. A review still starts on every push, from **Review a pull request** under Reviews, which posts on the pull request as a push would, and with `php artisan review:start <repository> <branch>`.
- **Pull requests from forks**, on the same page, are not reviewed by themselves unless that is turned on: a fork's code is anyone's, and a review runs it in the sandbox with the tenant's AI key and on its budget. Turned on, a pull request from a fork on GitHub is reviewed on every push and on a comment, as any other; off, it waits under **Pull requests**, and a `/review` comment or a question on one gets a reply saying so. On Bitbucket, which keeps no copy of a fork's branch in the repository, a pull request from a fork is never reviewed by itself; it can be [tried](#trying-a-pull-request) from the diff alone.
- **Instructions** are what the review is told to look for: a method, the defect lenses and a guide per stack. The administrator keeps the defaults; a tenant can copy one and change it for its own repositories. What is saved is what the next review reads.

### Parts of the review

Claude Code, Codex and Pi follow the same instructions, and a review is built from parts besides the reviewer itself. Each is a setting under **Review defaults**, and a repository can set its own under **Review settings**:

| Setting | What it does | When nothing sets it |
|---|---|---|
| OpenCodeReview picks the files | `ocr delegate` says which changed files to review and gives a checklist per file. Off, the reviewer reviews every changed file against the repository's own rules and the stack guide. | On |
| Scan before the review | Added lines are checked for secrets and `composer.lock` against Packagist's advisories, before the reviewer starts. | On |
| Floor guard | Lists every place the change made a check easier to pass, for the reviewer to weigh. | On |
| Extra passes | The reviewer's own review first, for candidates: the review-toolkit agents and `/code-review` for Claude Code, `codex exec review` for Codex. Pi has none. | Off for Claude Code, on for Codex |
| Second check | Each new finding that would block is checked once more in a fresh session. Not for Pi. | Off |

OpenCodeReview as the reviewer, and a review from the diff alone, have none of these. Each run records which parts it ran with, under **Run details** and in a comparison, so two setups can be told apart.

### Rules

**Rules** in the sidebar are the team's own rules for its reviews, in plain language. A rule is for every repository, or for one, and can be limited to paths (`app/Http/**`). It is one of two kinds:

- **Check**: the reviewer reports a change that breaks it, citing the rule (R1, R2 and on), at the severity the rule sets or at its own judgement.
- **Not a defect here**: a pattern the reviewer does not report, because the team judged it a false positive or it is how the team works.

The reviewer reads them as `review-rules.md`, after the instructions, with the standing of `REVIEW.md`: they cannot lower the evidence a finding needs. A rule is part of the instructions' version, so changing one has the next push reviewed against it. A rule that is off stays on the list and is not read.

On a review, a finding marked *Not a problem* or *Factually wrong* offers **Make it a rule** (for the tenant's admins): a *Not a defect here* rule for that repository and file, to widen before saving. Everyone in the team reads the rules; its admins write them. **Import CSV** adds many at once: a header row, then a row per rule with `kind` (`check` or `convention`) and `title`, and optionally `body`, `paths` (globs separated by `;`), `severity` and `repository` (`workspace/name`, or empty for every repository). A rule already there with the same kind, title and repository is skipped, as is a row that names an unknown repository or kind, each with its row number.

### By pull request

Under **Review defaults → By pull request** a team can have each pull request classified before its review. A small model, with the AI provider that writes the notes on whose turn, reads the title, the description, the changed files with their size and the diff of the migrations, and calls the pull request **simple**, **normal** or **complex**. It also notes the kind of change and whether it touches the database, breaks what others rely on, matters for security, or has a migration that cannot be undone. Each commit is classified once, so every build of it runs the same.

| Classify each pull request | What happens |
|---|---|
| Off (default) | Nothing is classified. |
| Classify only | The classification and what it would pick show in the run's log and under Run details; the review runs at what is set. |
| Classify, and run at what it picks | The review runs at the effort and model the classification picks. |

**From effort** and **From model** are where a simple pull request starts; a normal one runs a step higher, a complex one two, up to Extra high and *smartest*:

| From effort | simple | normal | complex |
|---|---|---|---|
| Low | Low | Medium | High |
| Medium (default) | Medium | High | Extra high |
| High | High | Extra high | Extra high |

The model works the same along *cheapest*, *default* (the default start) and *smartest*, the Laravel AI SDK's tiers. What it picks replaces every reviewer's effort (Pi's in a trial too), and Claude Code's and Codex's model; OpenCodeReview keeps its model. A review from the diff alone runs at it the same way. A trial that names its own model or effort runs at those. When the classification fails, the review runs at what is set and the log says why. A repository can set all three for itself under **Review settings**. The summary at the top of Review defaults says what each complexity runs at.

### What holds the merge

A finding holds the merge from a severity on, per category: on Bitbucket it gets a task, on GitHub the review requests changes, and the commit's status is red. **What holds the merge** under Review defaults sets it for the team, and a repository's own Review settings over that, one category at a time. Left empty, a category is the team's, else the platform's:

| Category | The platform's |
|---|---|
| Every category | MEDIUM and HIGH |
| Security | Any severity |
| Tests | HIGH only |
| Maintainability | Never |

A category the platform does not set apart, such as correctness or performance, follows **Every category**. So *HIGH only* there still holds the merge on a security finding of any severity, unless Security is set too. Whatever is set, a finding below confidence 8, one that was there before the change, and, from the third follow-up on, a new one below HIGH never hold the merge. The run's log and its Code Insights report say which threshold it used.

### Follow-up rounds

A review of a pull request after its first is a follow-up: it re-checks the open findings and looks for new ones only in what changed since. So a review, a fix and another review do not go on for ever, the review server holds every follow-up to three rules, whatever the reviewer itself does:

- Once the review says it is **not converging** (the new findings sit in the fixes for the previous ones), only new HIGH and CRITICAL findings are kept. The rest are listed under *Open questions* in the summary, for a person.
- From the **third follow-up** on, a new finding below HIGH is still reported but no longer holds the merge, in later rounds neither.
- After **Follow-up rounds with new findings** (Review defaults, or a repository's own Review settings; five unless set), nothing new is recorded at all. The open findings are still checked, and what the review would add goes under *Open questions*.

Trials are not rounds of a pull request's review and are left alone.

### Budget per pull request

Follow-up rounds limit what a review may add; **Budget per pull request** limits what the reviews of one pull request may cost, in the team's currency. Set it under Review defaults, and a repository can set its own under Review settings. Once the reviews of a pull request have cost it, a push no longer starts one, and the pull request gets one comment saying so. A `/review` comment still starts a review each time. Trials do not count towards it. Empty is no limit.

## What the reviewer reads in the repository

A team writes down how its code works, and what counts as a defect there, in the repository itself. The reviewer reads these files where they exist, and they win over its general rules:

| File | What it is for |
|---|---|
| `CLAUDE.md` or `AGENTS.md` at the root | The codebase's conventions, for any coding agent. One in a subdirectory holds the rules for the files under it, and the nearer one wins. |
| Rule files with globs | One topic per file, with the files it is for in its front matter, in the formats coding agents share: Laravel Boost's `.ai/rules/*.md` and Claude Code's `.claude/rules/**/*.md` (`paths:`), GitHub Copilot's `.github/instructions/*.instructions.md` (`applyTo:`) and Cursor's `.cursor/rules/*.mdc` (`globs:`, or `alwaysApply: true`). The review gives the reviewer every one whose globs match a file the pull request changes, read from the destination branch so a pull request cannot loosen them; one without globs applies to every file. A rule file the pull request itself adds or changes is reviewed as part of it. |
| `REVIEW.md` at the root | Instructions for review only: what to report, at what severity, which paths to leave alone, checks to make on every pull request. Read from the destination branch, so a pull request cannot change the rules it is reviewed against. |
| `.ai/review-conventions.md` | Patterns judged false positives before, one per line, which the reviewer does not report again. A review lists candidates for it under **Suggested convention entries** at the end of its summary, to paste in when a person agrees. |
| `.opencodereview/rule.json` | OpenCodeReview's own rules, read from the destination branch. |

A rule kept in Review Central can be moved into the repository, where every agent tool reads it and it changes without a merge conflict. Ask an assistant with the agent server connected: `review-rules` gives the rules, says how to write each as a Boost rule file (`.ai/rules/<slug>.md` with `paths:`), and with `proposals` also gives rules the team's verdicts on dismissed findings suggest. The assistant writes the files, indexes them (`php artisan boost:index-rules`) and opens a pull request once you agreed.

After each merge, Review Central reads the rule files on the main branch and turns off each of the repository's own rules it finds there, so the review does not read a rule twice. A small model matches them by what they say, so a file needs no reference to the rule. The rule stays listed, turned off, under **In the repository** with the file it was found in; turn it on again to keep it, and later checks leave it on. **Check the repository** on the rules page, or `check-review-rules` from an assistant, runs the check at once. A rule for every repository stays on.

On a Laravel repository the reviewer also gets **REACH**: for each check the change adds or changes (a method named as one, such as `canBook`, `allows` or `moduleAccessError`), the entry points that reach it, and the entry points (controllers, Livewire components, jobs, commands, routes, views) that reach a method of the same class that writes without passing the check. A rule about who may do something has to hold on every path to what it guards, and the path the change never touches is the one a review misses. It is worked out with `git grep` by method name before the review starts, in a few seconds, so it names paths to look at and is never proof by itself.

With **Code index** on in the review settings (off by default, and one of the parts a trial can turn on), REACH follows the calls instead of the names. Before the review, [Mago](https://mago.carthage.software) analyses the checkout and resolves each call by the type of what it is called on, Eloquent's static calls such as `Lesson::findOrFail()` included, so `$lesson->pay()` reaches `Lesson::pay` and not every `pay()` there is. A call through a parent class or an interface counts where it can land on the class. It takes about 20 seconds and 3 GB of memory, and runs `composer install` when the checkout has no `vendor/`. A call on a value whose type is not known is not in the index, so a path can be missing; where the index cannot be built, REACH goes by name as without it.

None of them is needed: without them the reviewer goes by the review instructions and the stack guides alone. They can raise what the reviewer reports, never lower what it needs as evidence or change what it may run.

## A repository's page

Clicking a repository, under **Repositories** or on the overview, opens its page: the figures and charts of the overview for that repository alone over 7, 30 or 90 days, with what it cost and how many findings are left open, its open pull requests with how their last review ended, its latest reviews, the open findings of its merged pull requests, the rules its review reads, and each reviewer that ran there with how often the team accepted what it found. An admin adds a rule for it, and changes its review settings, from the page.

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

Where the tenant has an AI provider, a small model also writes it in a sentence or two from the description, the comments, the history and the AI review, and sums up what the pull request changes. Under **Review defaults**, **Notes on whose turn** turns this off for the team, or picks the model: by default the provider's cheapest as the Laravel AI SDK names it, or its default or smartest, or a model by name. It is written once each time the state changes; until then, and when it is off, the rules' own words show. `REVIEW_TURN_NOTES=false` turns it off for the whole installation. The account the review acts as is not counted as a reviewer.

**With me** lists the pull requests whose turn is yours. It goes by the accounts you connected on your profile (the menu at the top right), and the ones you list there for hosts you did not connect: your Bitbucket nickname or account ID, your GitHub login. They only tell which pull requests are yours and give no access. With none, your name is matched to the name the host shows. A row's details show the reviewers, the summary and the history: opened, pushed, asked to review, approved, changes requested, and each AI review.

The list opens on **Active**: the open pull requests that are not drafts. **Draft** lists the open drafts, and **All open** both. **Merged** and **Declined** list the pull requests closed that way in the last 90 days, with their history; after that they are removed. A declined pull request that is opened again, as GitHub allows, is back among the open ones; a merged one stays merged, also when a delivery from before arrives late.

Approvals and requests for changes reach Review Central through the webhook events listed under [Setting up a tenant](./tenants). `php artisan review:sync-pull-requests` reads every listed pull request from its host again, for those from before and for a delivery that was missed; it runs every night by itself.

### Open findings

When a pull request is merged into the main branch while its last review still had findings open (new or open, and not called *Not a problem* or *Wrong* by anyone in the team), they stay under **Open findings** in the sidebar, per repository. Trials do not count. The list shows each finding with its pull request, file and line, and where it stands:

- **Not checked yet**: kept at the merge.
- **Still there**, **Resolved** or **Unclear**: what the last check said, with its one-sentence reason.
- **Closed**: closed by someone, with why.

Under **Review defaults → Open findings after a merge** a team can have them checked **weekly** or **monthly** (off by default: kept, not checked). A small model, with the AI provider that writes the notes on whose turn, reads each finding's file as it is on the main branch, one request per file, and says whether the problem is still there. A finding whose file is gone is resolved; one without a file is not checked. An admin can also check findings now, all or a selection, and close or reopen them.

## In Claude and other AI assistants

Review Central is also an MCP server, so an AI assistant can read and act on the reviews as you. It has two addresses:

- **`/mcp/agent`**, for a coding agent in a checkout, such as Claude Code: the ticket the change is for, read from the tenant's ticket sources as the review reads it, with the screenshots and files on it (Asana, Jira, and the images in a GitHub issue), which the agent can look at; the review's instructions with the team's rules, to review a change the way the pull request's review will before it is pushed; a pull request's newest review with its findings and whether it merges into its destination (with the conflicting files on Bitbucket), the team's verdicts and the replies in each finding's thread; the open findings of the files it touches. It can ask the reviewer about a finding, settle a review's findings in one go (your verdict on each with why, and a reply in the finding's thread where the review should hear it, as you once you connected your [Bitbucket](./tenants#replying-on-bitbucket-as-yourself) or [GitHub](./tenants#replying-and-signing-in-with-github) account, else signed with your name), open the pull request under your name and rewrite the description of one of yours ([Opening pull requests as yourself](./tenants#opening-pull-requests-as-yourself)), start the review again without a push, and, for an admin, add a rule and settle the open findings backlog: have findings checked against the main branch once their fix is there, or close them with a reason.
- **`/mcp/user`**, for a chat, such as Claude: your tenants and repositories, the open pull requests and whose turn each is (or only those waiting for you), and the same tickets, reviews, findings and rules.

Add it with its address, and the assistant signs you in on Review Central's own page. Both addresses are also under **In Claude and other AI assistants** on your profile, ready to copy:

```sh
claude mcp add --transport http review-central https://review.example.com/mcp/agent
```

The name, `review-central` here, is what the profile tells people to give the server, and an agent's tools are called by it (`mcp__review-central__…`). An administrator sets it under **MCP server name** in Admin → Settings, so someone connected to two installations can tell them apart; without one it is the app's name as a slug.

It does no more than your account may: only your tenants' repositories, and rules and the open findings backlog only where you are an admin. Starting a review costs what a review costs, and the assistant is told to ask you first.

An assistant registers itself under a name of its own choosing, so the consent screen also shows where access goes: a web address such as `claude.ai`, or an app on your own computer. Only these may be signed in to: Claude (`claude.ai`, `claude.com`, the `claude://` scheme), ChatGPT (`chatgpt.com`), VS Code (`vscode.dev`, `insiders.vscode.dev`, `vscode://`, `vscode-insiders://`), Cursor (`cursor://`), and any client on the same computer through `http://localhost` or `http://127.0.0.1`, such as Claude Code. An administrator adds others under **Extra MCP redirect domains** in Admin → Settings, or with `MCP_REDIRECT_DOMAINS` in the [configuration](./configuration).

## A security review of a branch

**Security review**, under Reviews, has the reviewer read a whole branch for security problems as it stands, rather than one pull request's change: pick the repository and the branch, such as the default one. Nothing is posted; the report shows under the *Security* view. It reads far more than a pull request's review, so it takes longer and costs more. It needs a runner that reads the code, a Cloudflare sandbox or Docker, and an Anthropic connection, since Claude Code is its reviewer. Its model and effort are set with `SECURITY_REVIEW_MODEL` and the variables beside it in the [configuration](./configuration).

## Reviews and cost

![The overview of a tenant](/screenshots/overview.png)

![The list of reviews](/screenshots/reviews.png)

A tenant's panel opens on **Overview**: what needs attention (a repository that cannot start, high findings left open, runs that failed), the period's figures, reviews per day, findings by category, how findings ended and the repositories. **Reviews** lists the runs under the views *Needs action*, *Queued or running*, *Did not finish*, *Skipped or waiting*, *Trials* and *Security*, **Questions** lists what was asked with `@review` and what each answer cost, and a review's own page shows every run on its pull request, the findings and what the run cost. Cost is what the reviewer reported, or an estimate from its tokens and the price list when it reported none.

*Findings accepted* counts what people said of the findings: on a review's own page, where each finding takes *Valid, will fix*, *Not a problem* or *Factually wrong*, and on the rating page. Only a valid finding counts as accepted. It says nothing until findings are answered. A finding called *Not a problem* or *Factually wrong* takes a reason as well: *Nitpick*, *Out of scope*, *Intended*, *Handled elsewhere*, *Misread the code*, *Duplicate* or *Other*.

*How findings ended*, under the categories, counts each finding of the period by the last status a review of its pull request reported for it: *Fixed*, *Acknowledged*, *False positive*, *Still open*, *Code changed* when the code it was about is gone, or *Not reported again* when the reviewer could no longer establish it on unchanged code. Trials and reviews that failed to publish do not count. The review sets these from what it sees, whatever way people answered: a reply on the pull request, a resolved thread, a fix. Below it, the reasons people gave for the findings they dismissed. On a review's page, each finding shows who dismissed it, why, and their note.

On a repository, **Readiness** checks now whether it can be reviewed: that the connection can read it, that an AI provider is connected, that its runner is turned on, and whether the webhook ever delivered.

The admin panel opens on **Platform**: every tenant with its connections, repositories, reviews and the month's cost against its **monthly budget**, which is set on the tenant and stops nothing. A tenant reads its costs in US dollars or in euros, also set on the tenant, and its budget is in that currency. Its **time zone**, set there too and taken from the platform's settings (`UTC` unless changed), is the one its times are shown in and its days are counted in. A review is costed in dollars; euros are converted on the page at the latest reference rate of the European Central Bank (`review:sync-exchange-rates`), so a past month reads at today's rate. Below it, **Capacity** says whether the installation has room: how long container reviews wait for a worker and how long they take, how often every worker is busy, and the server's processor, memory and disk. Its advice names what to raise: `REVIEW_CONTAINER_PROCESSES` when reviews wait and the server has room, the server itself when it has not. Processor and memory advice for Docker counts only while a repository still reviews on Docker, and only the load while reviews ran there. For Cloudflare sandboxes it advises a size, with a button that switches to it: memory from every review, processor per size from the reviews on that size, never a size that was short of processor before, and after a change it waits for five reviews on the new size. A table per sandbox size shows what each step of the review took on average and for nine in ten, its most memory and how long it waited for a processor, with what a review took and cost in the sandbox. The figures come from a sample the scheduler takes each minute (`review:sample-capacity`), kept for 30 days, so they are empty until the scheduler runs.

## Trying a pull request

**Try without posting** on the list of reviews takes the address of a Bitbucket or GitHub pull request and reviews it without posting anything: no comment, no build status, no task. It is for comparing reviewers, models, efforts and instruction drafts on the same work, and the runs appear under the view *Trials*.

- The pull request may be merged already. It is then reviewed as it went in, against the destination branch as it was before the merge. One that was merged by fast-forward is refused, and one merged by rebase is reviewed on its last commit only: neither leaves a commit that says where the pull request began. A declined one is refused.
- Its repository need not be set up. It is added with *Review its pull requests* turned off, so nothing but a trial, or a review someone asks for with a `/review` comment, runs on it until someone turns that on. The workspace does need a source control connection, except a public GitHub repository.
- *Review as though nobody had* leaves out the comments and earlier findings on the pull request, and the summary an earlier review wrote into its description, so two runs start from the same place. Off, the run follows up on what is there.
- It runs on the runner picked in its form, whatever the repository itself uses.

### Comparing setups

On a review of a pull request, **Compare with** (for the tenant's admins) adds a row for each setup to try: runner, reviewer, model (picked from the same list as under Review defaults, or typed), effort and the [parts of the review](#parts-of-the-review), each on, off or as the repository has it, up to ten. *Plain review* turns every part off: the reviewer with the review's instructions and nothing else, to see what the parts add. Each row starts a trial at the commit the review read, even when the pull request has moved on since, so every setup reads the same code; with *At this review's commit* off, it reviews the pull request as it is now. An earlier commit is always reviewed as though nobody had, on Docker or a Cloudflare sandbox, and only while the pull request is open. **Comparisons** in the sidebar shows them side by side with the review it started from, labelled A, B, C and on: each one's outcome, cost, time and findings. A setup that cannot be tried says why, and the others still run. **Add setups** on the comparison adds more later; an analysis made before keeps to the reviews it read until it is run again. **Leave out** on a column moves it to the end, faded: it is not waited for, analysed or scored until **Bring back**.

Once every review has ended, **Analyse** has one model, picked with its AI provider (a Laravel AI tier or a model by name; the provider's smartest when none), read the pull request's diff, at the comparison's commit when it has one, and every review's findings. It groups the findings that are about the same issue, says of each whether it holds, is a false positive or cannot be settled from the diff, and which reviews raised it. Each column then shows how many of its findings hold, how many are false positives or unclear, and how many issues that hold it missed. The analysis is one request with the whole diff, and its cost shows with it.

Each published review links to a page where anyone who can read the pull request can rate it.
