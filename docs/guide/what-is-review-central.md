# What is Review Central?

Review Central is an AI review for pull requests that you host yourself, for one team or for several at once. Each team, a **tenant**, connects its own source control, ticket trackers and AI providers. Review Central decides when to review, hands the reviewer its context, posts the result on the pull request and keeps what every run found and cost.

![A review in Review Central](/screenshots/review.png)

## How a review runs

1. A push to a pull request reaches the server through the connection's webhook, which queues a review.
2. The job decides whether to review. A draft, a commit that was already reviewed and a pull request with a review still running are skipped before anything starts.
3. The reviewer (Claude Code, the Codex CLI, OpenCodeReview or Pi) runs on a checkout of the pull request in a sandbox, with the tenant's AI provider key. It reads the pull request, its comments, the ticket and the review instructions from the context the server gives it. A lighter [diff runner](./runners#the-diff-alone) sends only the diff and that context to the AI provider, and runs nothing.
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
| Source control | **GitHub**, with a personal access token, and **Bitbucket Cloud**: reviews start from their webhook and are posted on the pull request. Bitbucket also gets a task per finding that blocks the merge and a Code Insights report; GitHub has neither, and its review requests changes instead. Without a token, a public GitHub repository can be [tried](./reviews#trying-a-pull-request) without posting. **Any git host**, for cloning only. |
| Tickets | Jira, Asana, GitHub Issues |
| AI providers | Anthropic and OpenAI, or a gateway that speaks their API, for every reviewer. Gemini, Mistral, Groq, DeepSeek, xAI and OpenRouter for the review from the diff, and for Pi in a trial. |
| Reviewers | Claude Code, Codex CLI, OpenCodeReview, Pi, or the diff alone |
| Sandboxes | Docker with gVisor on your own server; Cloudflare sandboxes (experimental) |
| Stacks with their own guide | Laravel, Magento, Hyvä, Drupal; a tenant can write its own for any other |
| Signing in | Email and password, or Google, with the domains whose people may join a tenant |

New source control hosts, ticket trackers and providers are a driver class each: see [Extending](./extending).

## What it is not

- **Not a hosted service.** There is no account to sign up for. You run it, and you pay your AI provider directly.
- **Not a linter.** It looks for defects a person would raise in review: wrong behaviour, security holes, missing tests, and work the ticket asked for that is not there.
- **Not the last word.** Each finding can be marked *Valid, will fix*, *Not a problem* or *Factually wrong*, and the overview shows how often the team agrees with the reviewer.

Next: [try it in ten minutes](./quick-start).
