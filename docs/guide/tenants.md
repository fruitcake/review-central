# Setting up a tenant

In the admin panel, at `/admin`:

1. Add the tenant, and the users who may open it. A new user is mailed an invitation to choose a password, so the installation needs working mail (`MAIL_*` in `.env`) and a running queue. From the list of users the invitation can be sent again, and a password reset mailed.

In the tenant's panel, at `/app/<tenant>`:

2. **Source control**: add the host (Bitbucket Cloud, with a workspace access token that may write pull requests). **AI providers**: add a key (Anthropic, OpenAI, or another provider), with the model and effort Claude Code or Codex reviews at on it. "Test" checks the credentials.
3. **Tickets**, for ticket context: the tracker (Jira, Asana or GitHub issues) with its credentials, and which references in a pull request title name a ticket there: `PREFIX-123` with a list of prefixes, or `#1234`.
4. **Repositories**: choose the connection and search for the repository by name. The workspace is the connection's, and the stack is read from the repository's `composer.json` when it is added; editing the repository changes the stack, its name here and the instructions it reads.
5. On the source control connection, **Webhook** shows the address and secret. The webhook is what starts a review: for a push to a pull request, a `/review` comment and a draft marked ready. **Add it for me** adds it for every repository of the workspace, which needs the webhook scope on the token: Bitbucket has no screen for a workspace's webhooks, only an API. Without that scope, add it to each repository under Repository settings, Webhooks.

Added by hand, the webhook takes the pull request events Created, Updated, Merged, Declined and Comment created. Nothing is needed in the repository itself.

A tenant's own colours are set on the tenant: see [Appearance](./appearance).
