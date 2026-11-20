# Setting up a tenant

In the admin panel, at `/admin`:

1. Add the tenant, and the users who may open it. A new user is mailed an invitation to choose a password, so the installation needs working mail (`MAIL_*` in `.env`) and a running queue. From the list of users the invitation can be sent again, and a password reset mailed.

In the tenant's panel, at `/app/<tenant>`. Every connection form has **How to get this** next to the service you pick: a link to the page where its key or token is made, the steps there, and the scopes it needs with why.

2. **Source control**: add the host.
   - **GitHub**: a fine-grained personal access token for the repositories, with read access to Contents and Metadata, read and write access to Pull requests, Issues and Commit statuses, and Webhooks to have the webhook added for you. The review comments, approves and requests changes as the account that owns the token, so give it an account of its own, a machine user: GitHub does not let an account approve its own pull request. The owner is the user or organisation from the address. Without a token, a public repository is only read, and its pull requests can be tried.
   - **GitHub App**, where the installation has [one set up](#github-as-an-app): install the app on the organisation, add the connection with the organisation as its owner, and use **Connect** in the list. No token, and no webhook to add.
   - **Bitbucket Cloud**: a workspace access token that may write pull requests.
   - **Git**: clones any other host over HTTPS, and posts nothing.

   **AI providers**: add a key, with the model and effort Claude Code or Codex reviews at on it. "Test" checks the credentials.

   | Provider | Used by |
   |---|---|
   | Anthropic | Claude Code, OpenCodeReview, the review from the diff, questions on a pull request |
   | OpenAI | Codex, OpenCodeReview, the review from the diff |
   | Gemini, Mistral, Groq, DeepSeek, xAI, OpenRouter | The review from the diff, with that provider's own model; Pi in a trial |

   Anthropic and OpenAI take a base URL too, for a gateway that speaks their API.
3. **Tickets**, for ticket context: the tracker (Jira, Asana or GitHub issues) with its credentials, and which references in a pull request title name a ticket there: `PREFIX-123` with a list of prefixes, or `#1234`. Jira and Asana take a token, or a sign-in there when the installation has an OAuth app for them (see [below](#signing-in-to-asana-and-jira)).
4. **Repositories**: choose the connection and search for the repository by name. The workspace is the connection's, and the stack is read from the repository's `composer.json` when it is added; editing the repository changes the stack, its name here and the instructions it reads. Editing also moves it to another connection to the same host, such as from GitHub with a token to the [GitHub App](#github-as-an-app), with its history. **Remove** takes it off the list and stops its reviews, but keeps its reviews, questions and pull requests; adding it again, on this connection or another to the same host, brings it back with them. A removed source control connection likewise keeps the history of its repositories, and loses its credentials and webhook.
5. On the source control connection, **Webhook** shows the address and secret. The webhook is what starts a review: for a push to a pull request, a `/review` comment and a draft marked ready. **Add it for me** adds it for you; the screen says how to add it by hand. Nothing is needed in the repository itself.
   - **GitHub**: on the organisation, which covers every repository it has and gets, when the token may manage the organisation's webhooks; otherwise on each repository of the connection, so add the webhook again after adding a repository. By hand: the repository's or organisation's **Settings → Webhooks**, with this URL as the payload URL, content type `application/json`, the secret, and the events **Pull requests**, **Issue comments**, **Pull request review comments** and **Pull request reviews**.
   - **Bitbucket**: on every repository of the workspace, which needs the webhook scope on the token: Bitbucket has no screen for a workspace's webhooks, only an API. Without that scope, add it to each repository under **Repository settings → Webhooks**, with the pull request events Created, Updated, Approved, Approval removed, Changes request created, Changes request removed, Merged, Declined and Comment created.

## GitHub: the account behind the token

On GitHub the review acts as the account that owns the token. That has a few consequences:

- **The review shows under that account's name.** With your own token, its comments and reviews are yours. Give it an account of its own, a *machine user*: an ordinary GitHub account for the bot, such as `acme-review`, with a mailbox of its own and two-factor authentication. Invite it to the organisation with **Write** access to the repositories, and make the token on that account, with the organisation as its resource owner. If the organisation requires approval of fine-grained tokens, an owner approves it under the organisation's **Settings → Personal access tokens**. GitHub allows one machine user per person.
- **It cannot approve or request changes on its own pull requests.** GitHub refuses that for any account, so on a pull request opened by the token's owner the review posts its comments and description, and its verdict is refused; the run's details say so. Another reason for an account of its own.
- **The commit status always lands.** "AI Review" is red when a finding holds the merge, also where the verdict was refused. To have it hold the merge, make it a required status check in the branch's protection rules.
- **`@review` is someone's GitHub user name.** A question that starts with `@review` mentions that account, which on a public repository notifies a stranger. On a public repository, ask in a reply under one of the review's own comments instead: a reply there that names nobody is taken as a question for the reviewer when it reads as one.
- **No tasks and no check run.** GitHub has no tasks on a pull request, and only a GitHub App may create a check run with annotations: see [GitHub as an app](#github-as-an-app). The findings are comments on their lines, and the request for changes is what holds the merge.

A token expires: note the date, and replace it on the connection before then. **Test** on the connection says whether it still works.

A tenant's own colours are set on the tenant: see [Appearance](./appearance).

## GitHub as an app

Instead of a token per connection, the installation can be registered as a GitHub App, which tenants install on their organisation. The review then comments and approves as the app, `<name>[bot]`, which needs no machine user and no token that expires. Its state on a pull request is a check, *AI Review*, with the report as its summary and the findings as annotations on their lines under **Files changed**. While the review runs, the check shows how long it has taken and the last lines of its log, every 20 seconds, as the review's page does. On a public repository it shows only the review's steps: anyone can read the check there, and the reviewer's own words are words a pull request or a comment on it can put in its mouth. A pull request that waits for approval (see [Pull requests](./reviews#pull-requests)) gets a check that needs action, with an **Approve and review** button: pressed by one of the tenant's trusted accounts, it starts the review; anyone else's press does nothing.

An app cannot be added as a reviewer, and `@<app name>` notifies nobody. `/review` and `@review` work as with a token: the webhook brings every comment.

To register it, as the installation's administrator: on GitHub, under the organisation's (or your own) **Settings → Developer settings → GitHub Apps → New GitHub App**:

- **Callback URL**: `<APP_URL>/connections/oauth/callback`. Leave *Request user authorization (OAuth) during installation* off.
- **Webhook URL**: `<APP_URL>/api/webhooks/github-app`, with a secret of your own.
- **Repository permissions**: Metadata read; Contents, Pull requests, Issues, Commit statuses and Checks read and write. Contents write is only there because GitHub asks it of an app that resolves a review thread, as the review does for a fixed finding; nothing is pushed. Without it the review still says "Fixed in …" under the finding, and its log says the thread could not be resolved. **Organization permissions**: Members read, to check who owns an organisation.
- **Events**: Pull request, Issue comment, Pull request review comment, Pull request review, Check run.
- **Where can this GitHub App be installed**: *Any account*, for tenants outside your own organisation. That needs no verification by GitHub; it asks for one only for a paid listing in the Marketplace.

Then generate a private key and a client secret, and fill in the app under **Admin → Settings → Integrations**: its ID, the name in its address, the client ID and secret, the webhook secret and the contents of the private key. `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY` (the PEM file's path, or its contents) and `GITHUB_APP_WEBHOOK_SECRET` in `.env` do the same, and go first. Until all but the webhook secret are set, *GitHub App* is not offered as a connection, and the setup page says the app does not work while the webhook secret is missing.

**Connect** signs the person in at GitHub once, to check that they own the connection's owner: an owner of the organisation, or the user account itself. A member who only has access to some of its repositories cannot connect it. Their own token is revoked straight after. An installation belongs to one connection.

## Signing in with Google

With a Google OAuth client filled in under **Admin → Settings → Integrations**, or `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`, the sign-in page has a **Sign in with Google** button. Make the client in the Google Cloud console, of the type "Web application", with `<APP_URL>/sign-in/google/callback` as its redirect address. It is for the whole installation: it comes before any tenant is known, and who joins which tenant goes by the tenant's sign-in domains.

A user whose address is already known signs in as that user, when Google holds that address: a Gmail address, or one of a Google Workspace organisation at its own domain. Someone new joins a tenant only when the tenant lists their organisation's domain under **Sign-in domains** in the admin panel, and Google says the account is that organisation's; a personal account under the same domain does not count, and a Gmail address never joins one.

## Signing in to Asana and Jira

A ticket connection can sign in at Asana or Jira instead of taking a pasted token, when the installation is registered as an OAuth app there, with `<APP_URL>/connections/oauth/callback` as its redirect address. One app serves every tenant: each person who connects says yes with their own account, and the tokens are kept per connection. Fill in its client ID and secret under **Admin → Settings → Integrations**, or in `.env`, which goes first:

- **Asana**: a new app in the [developer console](https://app.asana.com/0/my-apps) (`ASANA_CLIENT_ID`, `ASANA_CLIENT_SECRET`). The app needs the read scopes in `ASANA_OAUTH_SCOPE`; leave that empty for an app with full permissions. Under **Manage distribution**, let it be used in any workspace, for tenants outside your own organisation.
- **Jira**: an OAuth 2.0 (3LO) app in the [Atlassian developer console](https://developer.atlassian.com/console/myapps/), with read access to Jira issues (`ATLASSIAN_CLIENT_ID`, `ATLASSIAN_CLIENT_SECRET`). Under **Distribution**, set it to sharing, so people of other organisations can authorize it.

Without an app, a connection takes a token only, and its form says why.

## Which apps a tenant may use

Asana, Jira and the GitHub App are on for every tenant once they are set up. On the tenant, in the admin panel, **Sign in through the installation's apps** turns one off for that tenant: its connections then take a token, its forms say signing in is not on for the team, and the GitHub App is not offered as a connection. An app set up later is on for every tenant until it is turned off. Signing in with Google is not one of these: who joins by it goes by the tenant's sign-in domains.
