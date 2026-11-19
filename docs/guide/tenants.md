# Setting up a tenant

In the admin panel, at `/admin`:

1. Add the tenant, and the users who may open it. A new user is mailed an invitation to choose a password, so the installation needs working mail (`MAIL_*` in `.env`) and a running queue. From the list of users the invitation can be sent again, and a password reset mailed.

In the tenant's panel, at `/app/<tenant>`:

2. **Source control**: add the host. **Bitbucket Cloud**, with a workspace access token that may write pull requests, is the one reviews are posted on. **GitHub** is read-only for now: a token (or none, for public repositories) to read and clone, so its pull requests can be tried without posting. **Git** clones any other host over HTTPS.

   **AI providers**: add a key, with the model and effort Claude Code or Codex reviews at on it. "Test" checks the credentials.

   | Provider | Used by |
   |---|---|
   | Anthropic | Claude Code, OpenCodeReview, the review from the diff, questions on a pull request |
   | OpenAI | Codex, OpenCodeReview, the review from the diff |
   | Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter | The review from the diff, with that provider's own model; Pi in a trial |

   Anthropic and OpenAI take a base URL too, for a gateway that speaks their API.
3. **Tickets**, for ticket context: the tracker (Jira, Asana or GitHub issues) with its credentials, and which references in a pull request title name a ticket there: `PREFIX-123` with a list of prefixes, or `#1234`. Jira and Asana take a token, or a sign-in there when the installation has an OAuth app for them (see [below](#signing-in-to-asana-and-jira)).
4. **Repositories**: choose the connection and search for the repository by name. The workspace is the connection's, and the stack is read from the repository's `composer.json` when it is added; editing the repository changes the stack, its name here and the instructions it reads.
5. On the source control connection, **Webhook** shows the address and secret. The webhook is what starts a review: for a push to a pull request, a `/review` comment and a draft marked ready. **Add it for me** adds it for every repository of the workspace, which needs the webhook scope on the token: Bitbucket has no screen for a workspace's webhooks, only an API. Without that scope, add it to each repository under Repository settings, Webhooks.

Added by hand, the webhook takes the pull request events Created, Updated, Merged, Declined and Comment created. Nothing is needed in the repository itself.

A tenant's own colours are set on the tenant: see [Appearance](./appearance).

## Signing in with Google

With `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` set, the sign-in page has a **Sign in with Google** button. Make an OAuth client in the Google Cloud console, of the type "Web application", with `<APP_URL>/sign-in/google/callback` as its redirect address.

A user whose address is already known signs in as that user, when Google holds that address: a Gmail address, or one of a Google Workspace organisation at its own domain. Someone new joins a tenant only when the tenant lists their organisation's domain under **Sign-in domains** in the admin panel, and Google says the account is that organisation's; a personal account under the same domain does not count, and a Gmail address never joins one.

## Signing in to Asana and Jira

A ticket connection can sign in at Asana or Jira instead of taking a pasted token, when the installation is registered as an OAuth app there, with `<APP_URL>/connections/oauth/callback` as its redirect address:

- **Asana**: a new app in the [developer console](https://app.asana.com/0/my-apps), with `ASANA_CLIENT_ID` and `ASANA_CLIENT_SECRET` from it. The app needs the read scopes in `ASANA_OAUTH_SCOPE`; leave that empty for an app with full permissions.
- **Jira**: an OAuth 2.0 (3LO) app in the [Atlassian developer console](https://developer.atlassian.com/console/myapps/), with read access to Jira issues, and `ATLASSIAN_CLIENT_ID` and `ATLASSIAN_CLIENT_SECRET` from it.

Without them, a connection takes a token only.
