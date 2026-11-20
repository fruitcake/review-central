# What is Review Central?

Review Central is an AI review for pull requests that you host yourself, for private and public repositories on **Bitbucket Cloud** and **GitHub**, and for one team or several at once. Each team, a **tenant**, connects its own source control, ticket trackers and AI providers. Review Central decides when to review, hands the reviewer its context, acts on the pull request and keeps what every run found and cost.

![A review in Review Central](/screenshots/review.png)

## Why not a step in the pipeline?

It was made for Bitbucket Cloud. On GitHub, AI review is a few clicks away: Copilot and a marketplace of review apps. Bitbucket has far less to choose from, and the usual answer is an AI CLI in a Bitbucket Pipelines step. Review Central started as one, and grew out of what that cannot do well; the same goes for such a step in GitHub Actions:

- **Cost and time.** A pipeline step clones the repository and installs its packages from scratch on every push, on build minutes, and its reviewer has forgotten the last review. Review Central keeps the installed `vendor/` per lock file and the Composer cache per repository, clones without file contents it does not need, resumes Claude Code's last session on the branch, and skips a draft, a commit it already reviewed and a push with nothing new for it. A commit pushed while a review runs can be reviewed in the same job, from where that review got to.
- **Context.** A step sees the diff. Review Central hands the reviewer the ticket the pull request is for, the conversation on it, its own earlier findings with what people replied, the repository's own rules (`CLAUDE.md`, `REVIEW.md` and the like) and a guide for its stack. See [what the reviewer reads](./reviews#what-the-reviewer-reads-in-the-repository).
- **Acting on it.** A step can comment. Review Central writes a summary into the description, comments per finding with a fix to apply, opens a task for what blocks the merge, approves or requests changes, resolves the threads of what was fixed, answers questions, and puts a report on the commit.
- **Oversight.** Whose turn each open pull request is, with what they have to do and its history; what each review cost, per team and per reviewer against a budget, how often the team agrees with the findings, and a way to try another model on the same pull request before switching to it.

## How a review runs

1. A push to a pull request reaches the server through the connection's webhook, which queues a review.
2. The job decides whether to review. A draft and a commit that was already reviewed are skipped before anything starts; a push during a running review waits for it (see [Carrying on with a new commit](./runners#carrying-on-with-a-new-commit)).
3. The reviewer (Claude Code, the Codex CLI or OpenCodeReview, and Pi in a trial) runs on a checkout of the pull request in a sandbox: a Cloudflare sandbox, which never holds the AI provider's key, or a container on your server, which holds it. It reads the pull request, its comments, the ticket and the review instructions from the context the server gives it. A lighter [diff runner](./runners#the-diff-alone) sends only the diff and that context to the AI provider, and runs nothing.
4. The server reads the report the reviewer wrote and posts it:
   - a summary in the pull request's description;
   - a comment per finding, and replies in the threads people answered;
   - tasks for what blocks the merge;
   - an approval or a request for changes.
5. On the next push the reviewer reads its own earlier findings and the replies to them, marks what was fixed and resolves those threads.

A `/review` comment, or marking a draft ready, starts a review without a push; a tenant can limit who may comment `/review`. A comment that starts with `@review` asks the reviewer a question, which it answers in a reply.

### When a person has to decide

A review that finds nothing blocking but names something only a person can settle, such as whether production data a migration deletes may go, neither approves nor requests changes. The description reads **Manual review required** with the points to check, and the pull request waits for a person's approval. That the review cannot run the code is never such a point.

## What it works with

| | Supported |
|---|---|
| Source control | **GitHub**, with a personal access token or as a [GitHub App](./tenants#github-as-an-app), and **Bitbucket Cloud**: reviews start from their webhook and are posted on the pull request. Bitbucket also gets a task per finding that blocks the merge and a Code Insights report; GitHub has neither, and its review requests changes instead. Without a token, a public GitHub repository can be [tried](./reviews#trying-a-pull-request) without posting. **Any git host**, for cloning only. |
| Tickets | Jira, Asana, GitHub Issues |
| AI providers | Anthropic and OpenAI, or a gateway that speaks their API, for every reviewer. Gemini, Mistral, Groq, DeepSeek, xAI and OpenRouter for the review from the diff, and for Pi in a trial. |
| Reviewers | Claude Code, Codex CLI, OpenCodeReview, or the diff alone; Pi in a trial |
| Sandboxes | Cloudflare sandboxes, the safest; Docker with gVisor on your own server |
| Stacks with their own guide | Laravel, Magento, Hyvä, Drupal; a tenant can write its own for any other |
| Signing in | Email and password, or Google, with the domains whose people may join a tenant |

New source control hosts, ticket trackers and providers are a driver class each: see [Extending](./extending).

## What it is not

- **Not a hosted service.** There is no account to sign up for. You run it, and you pay your AI provider directly.
- **Not a linter.** It looks for defects a person would raise in review: wrong behaviour, security holes, missing tests, and work the ticket asked for that is not there.
- **Not the last word.** Each finding can be marked *Valid, will fix*, *Not a problem* or *Factually wrong*, and the overview shows how often the team agrees with the reviewer.

Next: [try it in ten minutes](./quick-start).
